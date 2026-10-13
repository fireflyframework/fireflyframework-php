<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tests\Support;

use DateTimeImmutable;
use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Persistence\ExecutionCriteria;
use Firefly\Orchestration\Persistence\ExecutionPersistenceProvider;
use Firefly\Orchestration\Persistence\ExecutionState;
use Firefly\Orchestration\Persistence\MemoryExecutionPersistenceProvider;
use RuntimeException;

/**
 * An in-memory store that, once refuseLoadsOf() names an execution, throws when findById() reads one of that
 * execution's states — a store that fails part-way through, after the execution was written.
 */
final class UnreadableExecutionPersistence implements ExecutionPersistenceProvider
{
    private readonly MemoryExecutionPersistenceProvider $inner;

    /** @var list<string> */
    private array $refused = [];

    public function __construct()
    {
        $this->inner = new MemoryExecutionPersistenceProvider;
    }

    public function refuseLoadsOf(string $executionName): void
    {
        $this->refused[] = $executionName;
    }

    /** Reads past the refusal, so a test can assert what the store holds. */
    public function peek(string $correlationId): ?ExecutionState
    {
        return $this->inner->findById($correlationId);
    }

    public function save(ExecutionState $state): void
    {
        $this->inner->save($state);
    }

    public function findById(string $correlationId): ?ExecutionState
    {
        $state = $this->inner->findById($correlationId);
        if ($state !== null && in_array($state->executionName, $this->refused, true)) {
            throw new RuntimeException("cannot read [{$correlationId}]");
        }

        return $state;
    }

    public function find(ExecutionCriteria $criteria): array
    {
        return $this->inner->find($criteria);
    }

    public function findInFlight(int $limit = 100): array
    {
        return $this->inner->findInFlight($limit);
    }

    public function findStale(DateTimeImmutable $before, int $limit = 100): array
    {
        return $this->inner->findStale($before, $limit);
    }

    public function findDue(DateTimeImmutable $now, int $limit = 100): array
    {
        return $this->inner->findDue($now, $limit);
    }

    public function findBySearchAttributes(array $criteria, int $limit = 100): array
    {
        return $this->inner->findBySearchAttributes($criteria, $limit);
    }

    public function updateStatus(string $correlationId, ExecutionStatus $status, DateTimeImmutable $now): void
    {
        $this->inner->updateStatus($correlationId, $status, $now);
    }

    public function delete(string $correlationId): bool
    {
        return $this->inner->delete($correlationId);
    }

    public function cleanup(DateTimeImmutable $before): int
    {
        return $this->inner->cleanup($before);
    }

    public function countInFlight(): int
    {
        return $this->inner->countInFlight();
    }

    public function countByStatus(): array
    {
        return $this->inner->countByStatus();
    }

    public function isHealthy(): bool
    {
        return $this->inner->isHealthy();
    }
}
