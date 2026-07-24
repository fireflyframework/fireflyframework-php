<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Cache;

/**
 * The default QueryCache: every get() is a miss (null), put()/evict() are no-ops. The read pipeline therefore always
 * runs the handler until firefly/cache supplies a real adapter.
 */
final class NoOpQueryCache implements QueryCache
{
    public function get(string $key): mixed
    {
        return null;
    }

    public function put(string $key, mixed $value, ?int $ttl): void {}

    public function evict(string $key): void {}
}
