<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\CompetingBeanFixtures;

use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Primary;

/**
 * The application shape the whole fixture set exists for: TWO #[Bean] methods producing the SAME
 * type, distinguished only by name, with exactly one #[Primary].
 *
 * ContainerRegistrar::registerBeans() binds this as
 *   'memoryCache' => factory, 'redisCache' => factory, CachePort::class => alias('memoryCache')
 * — so CachePort::class is NOT a registration key of its own for either bean, which is the single
 * fact every boot pass in firefly/context used to get wrong by keying on $bean->returns.
 */
#[Configuration]
final class CacheConfiguration
{
    #[Bean('memoryCache')]
    #[Primary]
    public function memoryCache(CacheProbe $probe): CachePort
    {
        return new MemoryCache($probe);
    }

    #[Bean('redisCache')]
    public function redisCache(CacheProbe $probe): CachePort
    {
        return new RedisCache($probe);
    }
}
