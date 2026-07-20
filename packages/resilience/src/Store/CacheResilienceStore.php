<?php

declare(strict_types=1);

namespace Firefly\Resilience\Store;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;

/**
 * The default ResilienceStore: Laravel's cache. Every mutator maps to an atomic cache primitive
 * (add/increment/decrement), and withLock uses the store's atomic lock so a token-bucket or breaker
 * transition is a genuine critical section. State persists across FPM requests whenever the driver does
 * (file/database/redis); with the array driver it is per-request (fine for tests/single-shot).
 */
final class CacheResilienceStore implements ResilienceStore
{
    public function __construct(private readonly Repository $cache) {}

    public function get(string $key): mixed
    {
        return $this->cache->get($key);
    }

    public function put(string $key, mixed $value, ?float $ttlSeconds = null): void
    {
        $this->cache->put($key, $value, $ttlSeconds === null ? null : (int) ceil($ttlSeconds));
    }

    public function add(string $key, mixed $value, ?float $ttlSeconds = null): bool
    {
        return $this->cache->add($key, $value, $ttlSeconds === null ? null : (int) ceil($ttlSeconds));
    }

    public function increment(string $key, int $by = 1): int
    {
        $result = $this->cache->increment($key, $by);

        return is_int($result) ? $result : $by;
    }

    public function decrement(string $key, int $by = 1): int
    {
        $result = $this->cache->decrement($key, $by);

        return is_int($result) ? $result : -$by;
    }

    public function forget(string $key): void
    {
        $this->cache->forget($key);
    }

    public function withLock(string $key, float $ttlSeconds, callable $callback): mixed
    {
        $store = $this->cache->getStore();
        if (! $store instanceof LockProvider) {
            // The driver has no atomic lock (unusual): run without a critical section rather than fail hard.
            return $callback();
        }

        $seconds = (int) max(1, ceil($ttlSeconds > 0 ? $ttlSeconds : 5.0));

        return $store->lock($key.':lock', $seconds)->block($seconds, $callback);
    }
}
