<?php

declare(strict_types=1);

namespace Firefly\Observability\Tests\Fixtures\MeterTypeCollision;

use Firefly\Container\Attributes\Service;
use Firefly\Observability\Method\Counted;
use Firefly\Observability\Method\Observed;
use Firefly\Observability\Method\Timed;

/**
 * The pairing a Micrometer reader writes without thinking: one name, a timer for the latency and a counter for
 * the rate. A Prometheus metric NAME has exactly one type, so the two cannot coexist — the in-process registry
 * refuses the counter and the cache-backed one puts two `# TYPE` lines for `orders.place` in the exposition.
 *
 * The #[Observed] is here to prove the refusal is about the COLLISION and not about "three attributes on one
 * method": `orders.ship` is a second timer under a name of its own and is perfectly legal beside the other two.
 */
#[Service]
class CollidingMeterService
{
    #[Timed('orders.place')]
    #[Counted('orders.place')]
    #[Observed(name: 'orders.ship')]
    public function place(): void {}
}
