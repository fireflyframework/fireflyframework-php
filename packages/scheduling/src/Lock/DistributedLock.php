<?php

declare(strict_types=1);

namespace Firefly\Scheduling\Lock;

/**
 * The distributed-lock port (a ShedLock analog): the ScheduleWiringPass acquires a named lock before running a
 * scheduled task body and releases it after, so a task marked #[Scheduled(lock: ...)] runs on at most one node
 * per tick. NoneLock (default) and CacheLock ship here; PgAdvisoryLock ships in firefly/scheduling-postgres.
 */
interface DistributedLock
{
    /** Try to acquire $name for up to $ttlSeconds; false if it is already held elsewhere (never blocks). */
    public function tryAcquire(string $name, float $ttlSeconds): bool;

    public function release(string $name): void;
}
