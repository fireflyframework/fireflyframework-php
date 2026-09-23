<?php

declare(strict_types=1);

namespace Firefly\Observability\Tests\Fixtures\SharedTimerName;

use Firefly\Container\Attributes\Service;
use Firefly\Observability\Method\Observed;
use Firefly\Observability\Method\Timed;

/**
 * The other side of the type rule. #[Timed] and #[Observed] BOTH register timers, so one name across the two is
 * a legal (if redundant) pair of series rather than a conflict — the registry's guard is about the TYPE, and a
 * refusal here would reject a shape that records exactly what its author asked for.
 */
#[Service]
class SharedTimerNameService
{
    #[Timed('orders.ship')]
    #[Observed(name: 'orders.ship')]
    public function ship(): void {}
}
