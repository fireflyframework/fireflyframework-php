<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\CompetingBeanFixtures;

use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Primary;

/**
 * The concrete-return twin of CacheConfiguration. Same competing shape, but because
 * ConcreteCache IS scanned by ContextScanner, its #[AsEventListener] travels through
 * RegisterEventListenersPass's boot-time sweep instead of the late-bound extender path — so the
 * two configurations together cover both halves of listener registration in ONE boot.
 */
#[Configuration]
final class ConcreteCacheConfiguration
{
    #[Bean('nearCache')]
    #[Primary]
    public function nearCache(CacheProbe $probe): ConcreteCache
    {
        return new ConcreteCache($probe, 'near');
    }

    #[Bean('farCache')]
    public function farCache(CacheProbe $probe): ConcreteCache
    {
        return new ConcreteCache($probe, 'far');
    }
}
