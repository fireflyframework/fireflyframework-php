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
 *
 * #[Order(900)] is DELIBERATELY lower than SchedulingAutoConfiguration's #[Order(1000)] default bean, and
 * that gap is load-bearing, not cosmetic. When provider=postgres BOTH auto-configs are condition-eligible
 * to supply DistributedLock: this one via #[ConditionalOnProperty], scheduling's via
 * #[ConditionalOnMissingBean]. The incremental condition pass (Firefly\Context\Pass\ConditionPassTwoPass)
 * evaluates auto-config definitions sorted by (class-level #[Order], then FQCN), lowest FIRST, and adds
 * each survivor back to the registry before the next is evaluated. A lower #[Order] here GUARANTEES this
 * bean registers DistributedLock first, so scheduling's #[ConditionalOnMissingBean] then deterministically
 * sees it and backs off — the more-specific provider wins the race EXPLICITLY, independent of the FQCN
 * tiebreak (which today happens to favour this class but must not be relied on: a rename or a shift in the
 * sort would otherwise silently hand a provider=postgres app a NoneLock — no cross-node locking, no error).
 */
#[Configuration]
#[Order(900)]
final class PgAdvisoryLockAutoConfiguration
{
    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.scheduling.lock.provider', havingValue: 'postgres')]
    public function distributedLock(): DistributedLock
    {
        return new PgAdvisoryLock;
    }
}
