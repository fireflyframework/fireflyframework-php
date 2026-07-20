<?php

declare(strict_types=1);

namespace Firefly\Scheduling\Lock;

/**
 * The zero-dependency default lock: every acquire succeeds and release is a no-op. Correct for single-instance
 * deployments (the only "node" always wins) and for the skeleton, which boots with no lock infrastructure. Apps
 * that run more than one scheduler node switch to CacheLock (or the Postgres adapter) via
 * firefly.scheduling.lock.provider.
 */
final class NoneLock implements DistributedLock
{
    public function tryAcquire(string $name, float $ttlSeconds): bool
    {
        return true;
    }

    public function release(string $name): void {}
}
