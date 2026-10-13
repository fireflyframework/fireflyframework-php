<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tests\Support;

use DateTimeImmutable;
use Firefly\Orchestration\Core\Exception\OrchestrationException;
use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Persistence\ExecutionCriteria;
use Firefly\Orchestration\Persistence\ExecutionPersistenceProvider;
use Firefly\Orchestration\Persistence\ExecutionState;
use RuntimeException;

/**
 * An execution store whose lookup fails with a refusal that wraps a raw cause and withholds its sentence — the
 * shape the REST guard must not publish the message of. Everything else answers as an empty store.
 */
final class WithholdingPersistence implements ExecutionPersistenceProvider
{
    public const string RAW = 'SQLSTATE[08006] could not connect to server at 10.0.0.7 as orchestration_rw';

    public function save(ExecutionState $state): void {}

    public function findById(string $correlationId): ?ExecutionState
    {
        $cause = new RuntimeException(self::RAW);

        throw (new OrchestrationException("Loading execution [{$correlationId}] failed: {$cause->getMessage()}", 'ORCHESTRATION_STORE_FAILED', 500, $cause))
            ->withDetail(null);
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

    public function updateStatus(string $correlationId, ExecutionStatus $status, DateTimeImmutable $now): void {}

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
        return true;
    }
}
