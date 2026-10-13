<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Persistence;

use DateTimeImmutable;
use Firefly\Orchestration\Core\ExecutionStatus;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Throwable;

/**
 * Executions in three portable tables on any Laravel connection (PyFly's SqlAlchemyPersistenceProvider
 * shape): the columns every query filters on, plus the whole state as JSON in `payload`. save() is ONE
 * upsert — `ON CONFLICT … DO UPDATE` on PostgreSQL and SQLite, `ON DUPLICATE KEY UPDATE` on MySQL and MariaDB,
 * `MERGE` on SQL Server, all through Laravel's Builder::upsert() — with the search attributes rewritten in the
 * same transaction. Joining the caller's transaction is deliberate: a step that writes business rows inside
 * a #[Transactional] service commits or rolls back its checkpoint with them. Every value bound for a
 * width-limited column is checked (OrchestrationSchema::fit()) before the first statement runs.
 */
final class DatabaseExecutionPersistenceProvider implements ExecutionPersistenceProvider
{
    private const array UPDATED = ['execution_name', 'pattern', 'status', 'parent_correlation_id', 'updated_at', 'completed_at', 'wake_at', 'payload'];

    public function __construct(private readonly ConnectionInterface $connection) {}

    public function save(ExecutionState $state): void
    {
        OrchestrationSchema::fit('correlationId', $state->correlationId, OrchestrationSchema::ID_LENGTH);
        OrchestrationSchema::fit('parentCorrelationId', $state->parentCorrelationId, OrchestrationSchema::ID_LENGTH);
        OrchestrationSchema::fit('executionName', $state->executionName, OrchestrationSchema::NAME_LENGTH);

        $attributes = [];
        foreach ($state->searchAttributes as $key => $value) {
            $key = (string) $key;
            $value = ExecutionState::attributeValue($value);
            OrchestrationSchema::fit('searchAttributes key', $key, OrchestrationSchema::ATTRIBUTE_KEY_LENGTH);
            OrchestrationSchema::fit("searchAttributes.{$key}", $value, OrchestrationSchema::ATTRIBUTE_VALUE_LENGTH);
            $attributes[] = ['correlation_id' => $state->correlationId, 'attribute_key' => $key, 'attribute_value' => $value];
        }

        $row = [
            'correlation_id' => $state->correlationId,
            'execution_name' => $state->executionName,
            'pattern' => $state->pattern->value,
            'status' => $state->status->value,
            'parent_correlation_id' => $state->parentCorrelationId,
            'started_at' => OrchestrationSchema::instant($state->startedAt),
            'updated_at' => OrchestrationSchema::instant($state->updatedAt),
            'completed_at' => $state->completedAt === null ? null : OrchestrationSchema::instant($state->completedAt),
            'wake_at' => $state->wakeAt === null ? null : OrchestrationSchema::instant($state->wakeAt),
            'payload' => StateSerializer::encode($state),
        ];

        $this->connection->transaction(function () use ($row, $state, $attributes): void {
            $this->connection->table(OrchestrationSchema::EXECUTIONS)->upsert([$row], ['correlation_id'], self::UPDATED);
            $this->connection->table(OrchestrationSchema::SEARCH_ATTRIBUTES)->where('correlation_id', $state->correlationId)->delete();

            if ($attributes !== []) {
                $this->connection->table(OrchestrationSchema::SEARCH_ATTRIBUTES)->insert($attributes);
            }
        });
    }

    public function findById(string $correlationId): ?ExecutionState
    {
        $payload = $this->connection->table(OrchestrationSchema::EXECUTIONS)->where('correlation_id', $correlationId)->value('payload');

        return is_string($payload) ? StateSerializer::decode($payload) : null;
    }

    public function find(ExecutionCriteria $criteria): array
    {
        $query = $this->executions();
        if ($criteria->pattern !== null) {
            $query->where('pattern', $criteria->pattern->value);
        }
        if ($criteria->status !== null) {
            $query->where('status', $criteria->status->value);
        }
        if ($criteria->name !== null) {
            $query->where('execution_name', $criteria->name);
        }

        return $this->newestFirst($query, $criteria->limit);
    }

    public function findInFlight(int $limit = 100): array
    {
        return $this->newestFirst($this->executions()->whereNotIn('status', self::values(ExecutionStatus::terminal())), $limit);
    }

    public function findStale(DateTimeImmutable $before, int $limit = 100): array
    {
        return $this->newestFirst(
            $this->executions()->whereIn('status', self::values(ExecutionStatus::recoverable()))->where('updated_at', '<', OrchestrationSchema::instant($before)),
            $limit,
        );
    }

    public function findDue(DateTimeImmutable $now, int $limit = 100): array
    {
        return $this->decode($this->executions()
            ->where('status', ExecutionStatus::Waiting->value)
            ->whereNotNull('wake_at')
            ->where('wake_at', '<=', OrchestrationSchema::instant($now))
            ->orderBy('wake_at')
            ->orderBy('correlation_id')
            ->limit(max(0, $limit))
            ->pluck('payload')
            ->all());
    }

    public function findBySearchAttributes(array $criteria, int $limit = 100): array
    {
        $query = $this->connection->table(OrchestrationSchema::EXECUTIONS.' as e');
        foreach ($criteria as $key => $value) {
            $query->whereExists(static function (Builder $attribute) use ($key, $value): void {
                $attribute->selectRaw('1')
                    ->from(OrchestrationSchema::SEARCH_ATTRIBUTES.' as a')
                    ->whereColumn('a.correlation_id', 'e.correlation_id')
                    ->where('a.attribute_key', $key)
                    ->where('a.attribute_value', ExecutionState::attributeValue($value));
            });
        }

        return $this->decode($query->orderByDesc('e.updated_at')->orderBy('e.correlation_id')->limit(max(0, $limit))->pluck('e.payload')->all());
    }

    public function updateStatus(string $correlationId, ExecutionStatus $status, DateTimeImmutable $now): void
    {
        $state = $this->findById($correlationId);
        if ($state !== null) {
            $this->save($state->withStatus($status, $now));
        }
    }

    public function delete(string $correlationId): bool
    {
        return $this->connection->transaction(function () use ($correlationId): bool {
            $this->connection->table(OrchestrationSchema::SEARCH_ATTRIBUTES)->where('correlation_id', $correlationId)->delete();

            return $this->connection->table(OrchestrationSchema::EXECUTIONS)->where('correlation_id', $correlationId)->delete() > 0;
        });
    }

    public function cleanup(DateTimeImmutable $before): int
    {
        $ids = array_values(array_filter($this->executions()
            ->whereIn('status', self::values(ExecutionStatus::terminal()))
            ->where('updated_at', '<', OrchestrationSchema::instant($before))
            ->pluck('correlation_id')
            ->all(), 'is_string'));

        foreach (array_chunk($ids, 500) as $chunk) {
            $this->connection->transaction(function () use ($chunk): void {
                $this->connection->table(OrchestrationSchema::SEARCH_ATTRIBUTES)->whereIn('correlation_id', $chunk)->delete();
                $this->connection->table(OrchestrationSchema::EXECUTIONS)->whereIn('correlation_id', $chunk)->delete();
            });
        }

        return count($ids);
    }

    public function countInFlight(): int
    {
        return $this->executions()->whereNotIn('status', self::values(ExecutionStatus::terminal()))->count();
    }

    public function countByStatus(): array
    {
        $counts = [];
        foreach ($this->executions()->select('status')->selectRaw('count(*) as total')->groupBy('status')->get() as $row) {
            $status = isset($row->status) && is_string($row->status) ? $row->status : null;
            $total = isset($row->total) && is_numeric($row->total) ? (int) $row->total : 0;
            if ($status !== null) {
                $counts[$status] = $total;
            }
        }

        return $counts;
    }

    public function isHealthy(): bool
    {
        try {
            $this->executions()->limit(1)->get(['correlation_id']);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function executions(): Builder
    {
        return $this->connection->table(OrchestrationSchema::EXECUTIONS);
    }

    /** @return list<ExecutionState> */
    private function newestFirst(Builder $query, int $limit): array
    {
        return $this->decode($query->orderByDesc('updated_at')->orderBy('correlation_id')->limit(max(0, $limit))->pluck('payload')->all());
    }

    /**
     * @param  array<array-key, mixed>  $payloads
     * @return list<ExecutionState>
     */
    private function decode(array $payloads): array
    {
        $states = [];
        foreach ($payloads as $payload) {
            if (is_string($payload)) {
                $states[] = StateSerializer::decode($payload);
            }
        }

        return $states;
    }

    /**
     * @param  list<ExecutionStatus>  $statuses
     * @return list<string>
     */
    private static function values(array $statuses): array
    {
        return array_map(static fn (ExecutionStatus $status): string => $status->value, $statuses);
    }
}
