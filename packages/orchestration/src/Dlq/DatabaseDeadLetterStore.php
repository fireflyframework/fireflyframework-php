<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Dlq;

use DateTimeImmutable;
use DateTimeZone;
use Firefly\Orchestration\Persistence\ExecutionState;
use Firefly\Orchestration\Persistence\OrchestrationSchema;
use Firefly\Orchestration\Persistence\StateSerializer;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

/**
 * Dead letters in `firefly_orchestration_dead_letters` on any Laravel connection; save() is an upsert by id.
 * An id, name or type wider than its column is refused before the write (OrchestrationSchema::fit()), the
 * same on every driver; the error message, input and headers are unbounded text.
 */
final class DatabaseDeadLetterStore implements DeadLetterStore
{
    public function __construct(private readonly ConnectionInterface $connection) {}

    public function save(DeadLetterEntry $entry): void
    {
        OrchestrationSchema::fit('id', $entry->id, OrchestrationSchema::ID_LENGTH);
        OrchestrationSchema::fit('executionName', $entry->executionName, OrchestrationSchema::NAME_LENGTH);
        OrchestrationSchema::fit('correlationId', $entry->correlationId, OrchestrationSchema::ID_LENGTH);
        OrchestrationSchema::fit('stepId', $entry->stepId, OrchestrationSchema::NAME_LENGTH);
        OrchestrationSchema::fit('errorType', $entry->errorType, OrchestrationSchema::TYPE_LENGTH);

        $this->table()->upsert([[
            'id' => $entry->id,
            'execution_name' => $entry->executionName,
            'correlation_id' => $entry->correlationId,
            'pattern' => $entry->pattern->value,
            'step_id' => $entry->stepId,
            'status_at_failure' => $entry->statusAtFailure->value,
            'error_message' => $entry->errorMessage,
            'error_type' => $entry->errorType,
            'input' => StateSerializer::encodeArray($entry->input),
            'headers' => StateSerializer::encodeArray($entry->headers),
            'retry_count' => $entry->retryCount,
            'created_at' => OrchestrationSchema::instant($entry->createdAt),
            'last_retried_at' => $entry->lastRetriedAt === null ? null : OrchestrationSchema::instant($entry->lastRetriedAt),
        ]], ['id'], ['retry_count', 'last_retried_at', 'error_message', 'error_type']);
    }

    public function findById(string $id): ?DeadLetterEntry
    {
        return $this->entries($this->table()->where('id', $id)->limit(1))[0] ?? null;
    }

    public function findAll(int $limit = 100): array
    {
        return $this->entries($this->table()->orderByDesc('created_at')->orderBy('id')->limit(max(0, $limit)));
    }

    public function findByExecutionName(string $executionName, int $limit = 100): array
    {
        return $this->entries($this->table()->where('execution_name', $executionName)->orderByDesc('created_at')->orderBy('id')->limit(max(0, $limit)));
    }

    public function findByCorrelationId(string $correlationId): array
    {
        return $this->entries($this->table()->where('correlation_id', $correlationId)->orderByDesc('created_at')->orderBy('id'));
    }

    public function delete(string $id): bool
    {
        return $this->table()->where('id', $id)->delete() > 0;
    }

    public function count(): int
    {
        return $this->table()->count();
    }

    private function table(): Builder
    {
        return $this->connection->table(OrchestrationSchema::DEAD_LETTERS);
    }

    /** @return list<DeadLetterEntry> */
    private function entries(Builder $query): array
    {
        $entries = [];
        foreach ($query->get() as $row) {
            $data = (array) $row;
            $entries[] = DeadLetterEntry::fromArray([
                'id' => $data['id'] ?? '',
                'executionName' => $data['execution_name'] ?? '',
                'correlationId' => $data['correlation_id'] ?? '',
                'pattern' => $data['pattern'] ?? '',
                'stepId' => $data['step_id'] ?? null,
                'statusAtFailure' => $data['status_at_failure'] ?? '',
                'errorMessage' => $data['error_message'] ?? '',
                'errorType' => $data['error_type'] ?? '',
                'input' => is_string($data['input'] ?? null) ? StateSerializer::decodeArray($data['input']) : [],
                'headers' => is_string($data['headers'] ?? null) ? StateSerializer::decodeArray($data['headers']) : [],
                'retryCount' => $data['retry_count'] ?? 0,
                'createdAt' => is_string($data['created_at'] ?? null) ? ExecutionState::instant(new DateTimeImmutable($data['created_at'], new DateTimeZone('UTC'))) : 'now',
                'lastRetriedAt' => is_string($data['last_retried_at'] ?? null) ? ExecutionState::instant(new DateTimeImmutable($data['last_retried_at'], new DateTimeZone('UTC'))) : null,
            ]);
        }

        return $entries;
    }
}
