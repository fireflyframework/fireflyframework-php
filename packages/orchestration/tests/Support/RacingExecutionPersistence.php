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
 * An in-memory store where a live process gets in between a read and the next one: changeOnNextFind() saves
 * the given state just before the next findById() of its execution answers. findStale() never triggers it, so
 * the recovery sweep selects the old row and finds the new one once it holds the execution lock — exactly what
 * a long step that finishes, or checkpoints, while the sweep waits for its lock leaves behind.
 */
final class RacingExecutionPersistence implements ExecutionPersistenceProvider
{
    /** @var array<string, ExecutionState> */
    private array $changes = [];

    private readonly MemoryExecutionPersistenceProvider $inner;

    public function __construct()
    {
        $this->inner = new MemoryExecutionPersistenceProvider;
    }

    public function changeOnNextFind(ExecutionState $state): void
    {
        $this->changes[$state->correlationId] = $state;
    }

    public function save(ExecutionState $state): void
    {
        $this->inner->save($state);
    }

    public function findById(string $correlationId): ?ExecutionState
    {
        $change = $this->changes[$correlationId] ?? null;
        if ($change !== null) {
            unset($this->changes[$correlationId]);
            $this->inner->save($change);
        }

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
