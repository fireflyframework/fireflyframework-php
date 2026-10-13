<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tests\Support;

use DateTimeImmutable;
use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Persistence\ExecutionCriteria;
use Firefly\Orchestration\Persistence\ExecutionPersistenceProvider;
use Firefly\Orchestration\Persistence\ExecutionState;
use RuntimeException;

/** A store that is down: every write throws, every read finds nothing, health is DOWN. */
final class FailingExecutionPersistence implements ExecutionPersistenceProvider
{
    public function save(ExecutionState $state): void
    {
        throw new RuntimeException('database is down');
    }

    public function findById(string $correlationId): ?ExecutionState
    {
        return null;
    }

    public function find(ExecutionCriteria $criteria): array
    {
        return [];
    }

    public function findInFlight(int $limit = 100): array
    {
        return [];
    }

    public function findStale(DateTimeImmutable $before, int $limit = 100): array
    {
        return [];
    }

    public function findDue(DateTimeImmutable $now, int $limit = 100): array
    {
        return [];
    }

    public function findBySearchAttributes(array $criteria, int $limit = 100): array
    {
        return [];
    }

    public function updateStatus(string $correlationId, ExecutionStatus $status, DateTimeImmutable $now): void
    {
        throw new RuntimeException('database is down');
    }

    public function delete(string $correlationId): bool
    {
        return false;
    }

    public function cleanup(DateTimeImmutable $before): int
    {
        return 0;
    }

    public function countInFlight(): int
    {
        return 0;
    }

    public function countByStatus(): array
    {
        return [];
    }

    public function isHealthy(): bool
    {
        return false;
    }
}
