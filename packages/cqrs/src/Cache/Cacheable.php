<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Cache;

/**
 * OPT-IN contract a query implements to participate in the query-cache seam. cacheKey() returns the cache key, or
 * null to skip caching for this instance. Key derivation is left to the query (pyfly hashes fields; LaraFly defaults
 * to no-cache when the query is not Cacheable). The real cache adapter lands when firefly/cache ships.
 */
interface Cacheable
{
    public function cacheKey(): ?string;
}
