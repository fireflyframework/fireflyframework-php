<?php

declare(strict_types=1);

namespace Firefly\Resilience\Store;

/**
 * The cross-request state seam for the cache-backed patterns (CircuitBreaker/RateLimiter/Bulkhead). PHP-FPM
 * shares nothing between requests, so pattern state cannot live on the object — it lives here, behind ATOMIC
 * operations (add-if-absent, increment, and a withLock critical section) so concurrent workers never
 * over-admit through a read-modify-write race. The default adapter is Laravel's cache; an in-memory adapter
 * serves single-process code and tests.
 */
interface ResilienceStore
{
    public function get(string $key): mixed;

    public function put(string $key, mixed $value, ?float $ttlSeconds = null): void;

    /** Atomic add-if-absent: true only when THIS call created the key. */
    public function add(string $key, mixed $value, ?float $ttlSeconds = null): bool;

    /** Atomic increment; returns the new value (a missing key starts at zero). */
    public function increment(string $key, int $by = 1): int;

    /** Atomic decrement; returns the new value. */
    public function decrement(string $key, int $by = 1): int;

    public function forget(string $key): void;

    /**
     * Runs $callback while holding a short mutex named $key, so a compare-and-set section is race-free.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function withLock(string $key, float $ttlSeconds, callable $callback): mixed;
}
