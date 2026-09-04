<?php

declare(strict_types=1);

namespace Firefly\Container\Tests\Fixtures;

/**
 * Deliberately NOT annotated: produced exclusively by CacheConfig::redis().
 * Before the named-bean fix this instance was UNREACHABLE — 'redisCache' was
 * only an alias of Cache::class, which the last-registered factory owned.
 */
final class RedisCache implements Cache
{
    public function label(): string
    {
        return 'redis';
    }
}
