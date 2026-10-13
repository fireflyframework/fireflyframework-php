<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Attributes;

use Attribute;

/**
 * Opts a QUERY CLASS into the query cache: its results are stored under a key derived from the query value object
 * (Firefly\Cqrs\Cache\QueryCacheKey — the class plus its public properties), for $ttl seconds (null = the
 * firefly.cqrs.query.cache_ttl default, unset = no expiry), inside the invalidation group $group (null = the query
 * class itself). A group is what #[InvalidatesQuery] evicts when it names no single key. Inert metadata: compiled
 * into the HandlerManifest by HandlerScanner, consulted by DefaultQueryBus only when
 * firefly.cqrs.query.cache.provider=cache binds a real cache. PyFly's @cacheable, moved from the handler to the
 * query because the key is the query's.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class CachedQuery
{
    public function __construct(
        public readonly ?int $ttl = null,
        public readonly ?string $group = null,
    ) {}
}
