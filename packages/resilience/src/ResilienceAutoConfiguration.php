<?php

declare(strict_types=1);

namespace Firefly\Resilience;

use Firefly\Config\Config;
use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Resilience\Method\ResilienceMethodInterceptor;
use Firefly\Resilience\Store\CacheResilienceStore;
use Firefly\Resilience\Store\InMemoryResilienceStore;
use Firefly\Resilience\Store\ResilienceStore;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Psr\Log\LoggerInterface;

/**
 * Always-on resilience wiring (an empty registry is cheap). #[Order(1000)] places it after user definitions;
 * each bean backs off #[ConditionalOnMissingBean] so an app that supplies its own store/registry wins. The
 * store is chosen by firefly.resilience.store.provider: `cache` (the default — cross-FPM survival on Laravel's
 * cache, which must have atomic locks unless firefly.resilience.store.allow-lockless=true) or `memory` (one
 * process: tests, CLI, a single long-running worker); the registry reads firefly.resilience.* and shares it.
 */
#[Configuration]
#[Order(1000)]
final class ResilienceAutoConfiguration
{
    #[Bean]
    #[ConditionalOnMissingBean(ResilienceStore::class)]
    public function resilienceStore(Repository $cache, Config $config, ?LoggerInterface $logger = null): ResilienceStore
    {
        $provider = $config->string('firefly.resilience.store.provider', 'cache');

        if ($provider === 'memory') {
            return new InMemoryResilienceStore;
        }

        if ($provider !== 'cache') {
            throw new ConfigurationException("firefly.resilience.store.provider must be 'cache' or 'memory', got '{$provider}'.");
        }

        $allowLockless = $config->bool('firefly.resilience.store.allow-lockless', false);
        $store = $cache->getStore();

        // Refused HERE, at boot: the bean is an eager singleton, so a deployment on a lockless store learns it
        // before the first request rather than in the middle of an outage the breaker was meant to contain.
        if (! $store instanceof LockProvider && ! $allowLockless) {
            throw CacheResilienceStore::lockless($store);
        }

        return new CacheResilienceStore($cache, $this->lockBlockTimeout($config), $allowLockless, $logger);
    }

    #[Bean]
    #[ConditionalOnMissingBean(ResilienceRegistry::class)]
    public function resilienceRegistry(Config $config, ResilienceStore $store): ResilienceRegistry
    {
        return ResilienceRegistry::fromConfig($config, $store);
    }

    /**
     * The chain link behind the six resilience attributes. Gated on `firefly.resilience.method.enabled`
     * (default true — an attribute is itself the opt-in, so the key exists to switch the mechanism OFF in
     * one place). When it is off the advice is inert and the proxies run a pass-through link: the patterns
     * are never half-applied.
     */
    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.resilience.method.enabled', havingValue: 'true', matchIfMissing: true)]
    #[ConditionalOnMissingBean(ResilienceMethodInterceptor::class)]
    public function resilienceMethodInterceptor(ResilienceRegistry $registry, Config $config): ResilienceMethodInterceptor
    {
        return new ResilienceMethodInterceptor($registry, $config);
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
