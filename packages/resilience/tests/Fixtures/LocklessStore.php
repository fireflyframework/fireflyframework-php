<?php

declare(strict_types=1);

namespace Firefly\Resilience\Tests\Fixtures;

use Illuminate\Contracts\Cache\Store;

/**
 * A cache store with NO atomic locks — what Laravel's apc and session stores are. Values live in an array so the
 * resilience patterns can run on it when allow-lockless says they may.
 */
final class LocklessStore implements Store
{
    /** @var array<string, mixed> */
    private array $values = [];

    public function get($key): mixed
    {
        return $this->values[$key] ?? null;
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<string, mixed>
     */
    public function many(array $keys): array
    {
        $found = [];
        foreach ($keys as $key) {
            $found[$key] = $this->values[$key] ?? null;
        }

        return $found;
    }

    public function put($key, $value, $seconds): bool
    {
        $this->values[$key] = $value;

        return true;
    }

    /** @param  array<string, mixed>  $values */
    public function putMany(array $values, $seconds): bool
    {
        foreach ($values as $key => $value) {
            $this->values[$key] = $value;
        }

        return true;
    }

    public function increment($key, $value = 1): int
    {
        $current = $this->values[$key] ?? 0;
        $next = (is_int($current) ? $current : 0) + (is_numeric($value) ? (int) $value : 1);
        $this->values[$key] = $next;

        return $next;
    }

    public function decrement($key, $value = 1): int
    {
        return $this->increment($key, -(is_numeric($value) ? (int) $value : 1));
    }

    public function forever($key, $value): bool
    {
        return $this->put($key, $value, 0);
    }

    public function touch($key, $seconds): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function forget($key): bool
    {
        unset($this->values[$key]);

        return true;
    }

    public function flush(): bool
    {
        $this->values = [];

        return true;
    }

    public function getPrefix(): string
    {
        return '';
    }
}
