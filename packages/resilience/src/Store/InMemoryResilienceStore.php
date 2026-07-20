<?php

declare(strict_types=1);

namespace Firefly\Resilience\Store;

/**
 * A single-process ResilienceStore for tests and single-shot CLI use. Atomicity is trivial (one process,
 * one thread), and withLock simply runs the callback. TTLs are ignored — the backing array lives for the
 * duration of the process.
 */
final class InMemoryResilienceStore implements ResilienceStore
{
    /** @var array<string, mixed> */
    private array $values = [];

    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    public function put(string $key, mixed $value, ?float $ttlSeconds = null): void
    {
        $this->values[$key] = $value;
    }

    public function add(string $key, mixed $value, ?float $ttlSeconds = null): bool
    {
        if (array_key_exists($key, $this->values)) {
            return false;
        }

        $this->values[$key] = $value;

        return true;
    }

    public function increment(string $key, int $by = 1): int
    {
        $current = $this->values[$key] ?? 0;
        $current = is_int($current) ? $current : 0;
        $next = $current + $by;
        $this->values[$key] = $next;

        return $next;
    }

    public function decrement(string $key, int $by = 1): int
    {
        return $this->increment($key, -$by);
    }

    public function forget(string $key): void
    {
        unset($this->values[$key]);
    }

    public function withLock(string $key, float $ttlSeconds, callable $callback): mixed
    {
        return $callback();
    }
}
