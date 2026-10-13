<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Persistence;

use DateTimeImmutable;
use Firefly\Orchestration\Core\ExecutionStatus;

/**
 * Where execution checkpoints live (jafly ExecutionPersistenceProvider). Selected by
 * `firefly.orchestration.persistence.provider`: `memory` (default — one process, lost on exit), `cache` (the
 * Laravel cache; shared across processes on redis/memcached/database stores) or `database` (portable tables on
 * any Laravel connection). W3 adds `event-sourced`. Every provider passes the same ExecutionPersistenceContract.
 *
 * save() is an UPSERT keyed by correlation id. Concurrency between writers is not this port's job: every
 * mutation of an execution runs under ExecutionLock (spec decision D6).
 */
interface ExecutionPersistenceProvider
{
    public function save(ExecutionState $state): void;

    public function findById(string $correlationId): ?ExecutionState;

    /** @return list<ExecutionState> newest update first, then by correlation id */
    public function find(ExecutionCriteria $criteria): array;

    /** @return list<ExecutionState> every execution whose status is not terminal */
    public function findInFlight(int $limit = 100): array;

    /** @return list<ExecutionState> recoverable-status executions last updated before $before */
    public function findStale(DateTimeImmutable $before, int $limit = 100): array;

    /** @return list<ExecutionState> WAITING executions whose wake-up instant is at or before $now, earliest first */
    public function findDue(DateTimeImmutable $now, int $limit = 100): array;

    /**
     * @param  array<string, string|int|float|bool>  $criteria  every attribute must equal (ExecutionState::attributeValue form)
     * @return list<ExecutionState>
     */
    public function findBySearchAttributes(array $criteria, int $limit = 100): array;

    public function updateStatus(string $correlationId, ExecutionStatus $status, DateTimeImmutable $now): void;

    public function delete(string $correlationId): bool;

    /** Deletes terminal executions last updated before $before; returns how many. */
    public function cleanup(DateTimeImmutable $before): int;

    public function countInFlight(): int;

    /** @return array<string, int> status value => executions */
    public function countByStatus(): array;

    public function isHealthy(): bool;
}
