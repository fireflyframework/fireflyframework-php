<?php

declare(strict_types=1);

namespace Firefly\Container\Tests\Fixtures;

use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Primary;

/**
 * Two #[Bean] methods returning the SAME type — the shape that used to collapse.
 *
 * Both names were registered as `alias(Cache::class, $name)`, so 'memoryCache'
 * and 'redisCache' were two aliases of ONE key and both resolved to whichever
 * factory happened to be registered last. #[Primary] on a #[Bean] method was
 * read nowhere at all, so it could not break the tie either.
 */
#[Configuration]
final class CacheConfig
{
    #[Bean('memoryCache')]
    #[Primary]
    public function memory(): Cache
    {
        return new MemoryCache;
    }

    #[Bean('redisCache')]
    public function redis(): Cache
    {
        return new RedisCache;
    }
}
