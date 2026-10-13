<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Cache;

/**
 * The query cache with GROUPS — the unit #[InvalidatesQuery] evicts when it names no single key (PyFly's "tag /
 * prefix" eviction). It extends QueryCache rather than changing it, so an application that bound its own QueryCache
 * keeps working: DefaultQueryBus only calls these methods when the bound cache implements them, and falls back to
 * get()/put() otherwise. The legacy methods of an implementation act on the default group ''.
 */
interface GroupedQueryCache extends QueryCache
{
    /** The cached value, or null on a miss. */
    public function lookup(string $group, string $key): mixed;

    /** Stores a non-null value for $ttl seconds (null = no expiry). */
    public function store(string $group, string $key, mixed $value, ?int $ttl): void;

    public function evictKey(string $group, string $key): void;

    /** Makes every entry of the group unreachable. */
    public function evictGroup(string $group): void;
}
