<?php

declare(strict_types=1);

namespace Firefly\Resilience;

use Firefly\Config\Config;
use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;
use Firefly\Resilience\Store\CacheResilienceStore;
use Firefly\Resilience\Store\ResilienceStore;
use Illuminate\Contracts\Cache\Repository;

/**
 * Always-on resilience wiring (an empty registry is cheap). #[Order(1000)] places it after user definitions;
 * each bean backs off #[ConditionalOnMissingBean] so an app that supplies its own store/registry wins. The
 * default store is Cache-backed (cross-FPM survival); the registry reads firefly.resilience.* and shares it.
 */
#[Configuration]
#[Order(1000)]
final class ResilienceAutoConfiguration
{
    #[Bean]
    #[ConditionalOnMissingBean(ResilienceStore::class)]
    public function resilienceStore(Repository $cache): ResilienceStore
    {
        return new CacheResilienceStore($cache);
    }

    #[Bean]
    #[ConditionalOnMissingBean(ResilienceRegistry::class)]
    public function resilienceRegistry(Config $config, ResilienceStore $store): ResilienceRegistry
    {
        return ResilienceRegistry::fromConfig($config, $store);
    }
}
