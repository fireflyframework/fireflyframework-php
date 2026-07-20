<?php

declare(strict_types=1);

namespace Firefly\Scheduling;

use Firefly\Config\Config;
use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;
use Firefly\Scheduling\Lock\CacheLock;
use Firefly\Scheduling\Lock\DistributedLock;
use Firefly\Scheduling\Lock\NoneLock;
use Illuminate\Contracts\Cache\Repository;

/**
 * Selects the DistributedLock backend by firefly.scheduling.lock.provider: `none` (NoneLock, the default —
 * single-instance / zero-coordination) or `cache` (CacheLock over the app's atomic Cache lock). #[Order(1000)]
 * places it after user definitions; #[ConditionalOnMissingBean] lets an app bind its own DistributedLock (or the
 * firefly/scheduling-postgres adapter supply `postgres`) and win. The Repository is injected exactly as
 * ResilienceAutoConfiguration::resilienceStore injects it — a real app always has a cache store bound.
 */
#[Configuration]
#[Order(1000)]
final class SchedulingAutoConfiguration
{
    #[Bean]
    #[ConditionalOnMissingBean(DistributedLock::class)]
    public function distributedLock(Config $config, Repository $cache): DistributedLock
    {
        return match ($config->string('firefly.scheduling.lock.provider', 'none')) {
            'cache' => new CacheLock($cache),
            default => new NoneLock,
        };
    }
}
