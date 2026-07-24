<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Cache;

/**
 * The query-cache SEAM the query bus consults around the handler. NoOpQueryCache (always-miss) is the shipped
 * default — inert exactly like #[Valid] was in M5 — until firefly/cache lands a real adapter. get() returns null on
 * a miss; the bus only caches/looks-up when the query is Cacheable and yields a non-null key.
 */
interface QueryCache
{
    public function get(string $key): mixed;

    public function put(string $key, mixed $value, ?int $ttl): void;

    public function evict(string $key): void;
}
