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
    public function resilienceStore(Repository $cache, Config $config): ResilienceStore
    {
        return new CacheResilienceStore($cache, $this->lockBlockTimeout($config));
    }

    #[Bean]
    #[ConditionalOnMissingBean(ResilienceRegistry::class)]
    public function resilienceRegistry(Config $config, ResilienceStore $store): ResilienceRegistry
    {
        return ResilienceRegistry::fromConfig($config, $store);
    }

    /**
     * How long a resilience primitive waits for the shared state mutex before failing fast, from
     * `firefly.resilience.store.lock-block-timeout` (a duration string such as `250ms`, or a bare number of
     * seconds).
     *
     * This is exposed as configuration rather than left hardcoded because the right answer depends on the
     * cache driver: an in-process array store or a local Redis hands the mutex over in microseconds, while a
     * database-backed cache across an availability zone can legitimately need tens of milliseconds. The
     * previous hardcoded five seconds was the worst of both — long enough to turn a contended fail-fast rate
     * limiter into a five-second stall on an FPM worker, and applied identically to every deployment.
     */
    private function lockBlockTimeout(Config $config): float
    {
        $value = $config->get('firefly.resilience.store.lock-block-timeout');

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        return is_string($value) ? Duration::parse($value) : CacheResilienceStore::DEFAULT_LOCK_BLOCK_TIMEOUT;
    }
}
