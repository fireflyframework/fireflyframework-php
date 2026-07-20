<?php

declare(strict_types=1);

namespace Firefly\Scheduling\Lock;

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Str;

/**
 * The default multi-node lock: a non-blocking wrapper over Laravel's atomic Cache lock. tryAcquire() asks the
 * cache store's LockProvider for a named lock scoped to a per-instance owner token and calls get() (which returns
 * immediately — true if acquired, false if held elsewhere). The acquired Lock is stashed per name so release()
 * can hand it back; because the owner token is unique per process, a foreign owner can never release our lock.
 * Works over every lock-capable driver (array/database/redis/memcached). The ttl is whole seconds (Cache::lock
 * granularity), floored at 1; a session-scoped, TTL-less alternative is the Postgres advisory adapter.
 */
final class CacheLock implements DistributedLock
{
    /** @var array<string, Lock> the currently-held locks, keyed by name, so release() can call ->release() */
    private array $held = [];

    private readonly string $owner;

    public function __construct(
        private readonly Repository $cache,
        ?string $owner = null,
    ) {
        $this->owner = $owner ?? Str::random(40);
    }

    public function tryAcquire(string $name, float $ttlSeconds): bool
    {
        $store = $this->cache->getStore();
        if (! $store instanceof LockProvider) {
            throw new ConfigurationException(
                'The configured cache store does not support the atomic locks CacheLock requires; use a '
                .'lock-capable driver (array/database/redis/memcached) or the [none] lock provider.',
            );
        }

        $lock = $store->lock($name, (int) max(1, (int) ceil($ttlSeconds)), $this->owner);

        if ($lock->get() === true) {
            $this->held[$name] = $lock;

            return true;
        }

        return false;
    }

    public function release(string $name): void
    {
        $lock = $this->held[$name] ?? null;
        if ($lock === null) {
            return;
        }

        $lock->release();
        unset($this->held[$name]);
    }
}
