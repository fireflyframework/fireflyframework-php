<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Dlq;

use DateTimeImmutable;
use Firefly\Orchestration\Core\Exception\DeadLetterNotFoundException;
use Firefly\Orchestration\Event\OrchestrationEvents;

/**
 * The engines' and operators' door to the DLQ (jafly DeadLetterService): deadLetter() stores an entry and
 * fires onDeadLettered, unless `firefly.orchestration.dlq.enabled` is false, in which case it drops it — the
 * failure is still in the execution's own checkpoint and report.
 */
final class DeadLetterService
{
    public function __construct(
        private readonly DeadLetterStore $store,
        private readonly OrchestrationEvents $events,
        private readonly bool $enabled = true,
    ) {}

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function deadLetter(DeadLetterEntry $entry): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->store->save($entry);
        $this->events->onDeadLettered($entry->executionName, $entry->correlationId, $entry->stepId, $entry->errorMessage);
    }

    /** @return list<DeadLetterEntry> */
    public function all(int $limit = 100): array
    {
        return $this->store->findAll($limit);
    }

    public function find(string $id): ?DeadLetterEntry
    {
        return $this->store->findById($id);
    }

    public function get(string $id): DeadLetterEntry
    {
        return $this->store->findById($id) ?? throw new DeadLetterNotFoundException($id);
    }

    /** @return list<DeadLetterEntry> */
    public function byExecutionName(string $executionName, int $limit = 100): array
    {
        return $this->store->findByExecutionName($executionName, $limit);
    }

    /** @return list<DeadLetterEntry> */
    public function byCorrelationId(string $correlationId): array
    {
        return $this->store->findByCorrelationId($correlationId);
    }

    public function delete(string $id): bool
    {
        return $this->store->delete($id);
    }

    public function count(): int
    {
        return $this->store->count();
    }

    public function markRetried(string $id, DateTimeImmutable $now): DeadLetterEntry
    {
        $retried = $this->get($id)->withRetry($now);
        $this->store->save($retried);

        return $retried;
    }
}
