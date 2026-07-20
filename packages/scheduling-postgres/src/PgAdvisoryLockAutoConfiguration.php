<?php

declare(strict_types=1);

namespace Firefly\Scheduling\Postgres;

use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Scheduling\Lock\DistributedLock;

/**
 * Opts a DistributedLock bean into the Postgres advisory-lock backend, but ONLY when the app explicitly
 * asks for it via firefly.scheduling.lock.provider=postgres — installing this package is otherwise inert.
 * #[Order(1000)] matches SchedulingAutoConfiguration's own #[Order(1000)] default bean (which backs off via
 * #[ConditionalOnMissingBean] if this bean already bound DistributedLock).
 */
#[Configuration]
#[Order(1000)]
final class PgAdvisoryLockAutoConfiguration
{
    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.scheduling.lock.provider', havingValue: 'postgres')]
    public function distributedLock(): DistributedLock
    {
        return new PgAdvisoryLock;
    }
}
