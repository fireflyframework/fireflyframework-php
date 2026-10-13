<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Orchestration;

use Closure;
use DateTimeImmutable;
use Firefly\EventSourcing\Aggregate\EventSourcedRepository;
use Firefly\EventSourcing\Aggregate\EventSourcingSupport;
use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Persistence\DatabaseExecutionPersistenceProvider;
use Firefly\Orchestration\Persistence\ExecutionCriteria;
use Firefly\Orchestration\Persistence\ExecutionPersistenceProvider;
use Firefly\Orchestration\Persistence\ExecutionState;
use Firefly\Orchestration\Persistence\OrchestrationSchema;
use Illuminate\Database\Connection;

/**
 * firefly.orchestration.persistence.provider=event-sourced: every execution is an ExecutionAggregate in the event
 * store (jafly's EventSourcedPersistenceProvider), with snapshots every firefly.eventsourcing.snapshot.threshold
 * events, and a READ MODEL for the queries the engines run.
 *
 * save() is ONE transaction on the event store's connection: load the aggregate (from its newest snapshot), record
 * the events that turn it into the given state, append them under the version it was loaded at, and upsert the read
 * model. Joining the caller's transaction is deliberate, as in the `database` provider: a step's business rows, its
 * checkpoint events and its read-model row commit or roll back together, and a reader of the read model always sees
 * its own writes. Concurrency between writers stays ExecutionLock's job; a writer that slipped past it gets the event
 * store's 409 ConcurrencyException rather than a lost update.
 *
 * findById() replays the aggregate, so the events are the truth. Every query reads the read model, which is W2's
 * own `firefly_orchestration_*` tables (DatabaseExecutionPersistenceProvider) on the same connection, or W2's memory
 * provider beside the memory event store. ExecutionReadModelProjection rebuilds it from the events.
 *
 * delete() and the retention cleanup append a tombstone and remove the read-model row: events are never purged.
 */
final class EventSourcedExecutionPersistenceProvider implements ExecutionPersistenceProvider
{
    /** How many times a top-level save() runs when the database reports a deadlock or serialization failure. */
    private const int ATTEMPTS = 3;

    /** @var EventSourcedRepository<ExecutionAggregate> */
    private readonly EventSourcedRepository $executions;

    public function __construct(
        private readonly EventSourcingSupport $support,
        private readonly ExecutionPersistenceProvider $readModel,
        private readonly ?Connection $connection = null,
    ) {
        $this->executions = OrchestrationEventTypes::repository($support);
    }

    /** @return EventSourcedRepository<ExecutionAggregate> */
    public function executions(): EventSourcedRepository
    {
        return $this->executions;
    }

    public function readModel(): ExecutionPersistenceProvider
    {
        return $this->readModel;
    }

    public function save(ExecutionState $state): void
    {
        OrchestrationSchema::fit('correlationId', $state->correlationId, OrchestrationSchema::ID_LENGTH);
        OrchestrationSchema::fit('parentCorrelationId', $state->parentCorrelationId, OrchestrationSchema::ID_LENGTH);
        OrchestrationSchema::fit('executionName', $state->executionName, OrchestrationSchema::NAME_LENGTH);

        $this->transactional(function () use ($state): void {
            $aggregate = $this->executions->find($state->correlationId) ?? new ExecutionAggregate($state->correlationId);
            $aggregate->checkpoint($state);
            $this->executions->save($aggregate);
            $this->readModel->save($state);
        });
    }

    public function findById(string $correlationId): ?ExecutionState
    {
        return $this->executions->find($correlationId)?->state();
    }

    public function find(ExecutionCriteria $criteria): array
    {
        return $this->readModel->find($criteria);
    }

    public function findInFlight(int $limit = 100): array
    {
        return $this->readModel->findInFlight($limit);
    }

    public function findStale(DateTimeImmutable $before, int $limit = 100): array
    {
        return $this->readModel->findStale($before, $limit);
    }

    public function findDue(DateTimeImmutable $now, int $limit = 100): array
    {
        return $this->readModel->findDue($now, $limit);
    }

    public function findBySearchAttributes(array $criteria, int $limit = 100): array
    {
        return $this->readModel->findBySearchAttributes($criteria, $limit);
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
        return $this->transactional(function () use ($correlationId): bool {
            $aggregate = $this->executions->find($correlationId);
            $this->readModel->delete($correlationId);
            if ($aggregate === null || $aggregate->state() === null) {
                return false;
            }

            $aggregate->delete();
            $this->executions->save($aggregate);

            return true;
        });
    }

    public function cleanup(DateTimeImmutable $before): int
    {
        $deleted = 0;
        foreach ($this->cleanupCandidates($before) as $correlationId) {
            if ($this->delete($correlationId)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    public function countInFlight(): int
    {
        return $this->readModel->countInFlight();
    }

    public function countByStatus(): array
    {
        return $this->readModel->countByStatus();
    }

    public function isHealthy(): bool
    {
        return $this->support->store->isHealthy() && $this->readModel->isHealthy();
    }

    /**
     * The terminal executions last updated before $before. The database read model answers with one indexed query
     * over W2's executions table; the memory one is listed per terminal status.
     *
     * @return list<string>
     */
    private function cleanupCandidates(DateTimeImmutable $before): array
    {
        if ($this->connection !== null && $this->readModel instanceof DatabaseExecutionPersistenceProvider) {
            return array_values(array_filter($this->connection->table(OrchestrationSchema::EXECUTIONS)
                ->whereIn('status', array_map(static fn (ExecutionStatus $status): string => $status->value, ExecutionStatus::terminal()))
                ->where('updated_at', '<', OrchestrationSchema::instant($before))
                ->orderBy('correlation_id')
                ->pluck('correlation_id')
                ->all(), 'is_string'));
        }

        $ids = [];
        foreach (ExecutionStatus::terminal() as $status) {
            foreach ($this->readModel->find(new ExecutionCriteria(status: $status, limit: PHP_INT_MAX)) as $state) {
                if ($state->updatedAt < $before) {
                    $ids[] = $state->correlationId;
                }
            }
        }
        sort($ids);

        return $ids;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private function transactional(Closure $work): mixed
    {
        return $this->connection === null ? $work() : $this->connection->transaction(static fn () => $work(), self::ATTEMPTS);
    }
}
