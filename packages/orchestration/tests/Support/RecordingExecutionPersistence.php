<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tests\Support;

use DateTimeImmutable;
use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Persistence\ExecutionCriteria;
use Firefly\Orchestration\Persistence\ExecutionPersistenceProvider;
use Firefly\Orchestration\Persistence\ExecutionState;
use Firefly\Orchestration\Persistence\MemoryExecutionPersistenceProvider;

/**
 * An in-memory store that also keeps a copy of every state it was asked to save, in order — each copy is what
 * a crash right after that checkpoint would leave behind for resume().
 */
final class RecordingExecutionPersistence implements ExecutionPersistenceProvider
{
    /** @var list<ExecutionState> */
    public array $saved = [];

    private readonly MemoryExecutionPersistenceProvider $inner;

    public function __construct()
    {
        $this->inner = new MemoryExecutionPersistenceProvider;
    }

    public function save(ExecutionState $state): void
    {
        $this->saved[] = ExecutionState::fromArray($state->toArray());
        $this->inner->save($state);
    }

    public function findById(string $correlationId): ?ExecutionState
    {
        return $this->inner->findById($correlationId);
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
