<?php

declare(strict_types=1);

namespace Firefly\Observability\Tests\Fixtures\Unstereotyped;

use Firefly\Observability\Method\Timed;

/**
 * A plain class — no #[Component]-family stereotype — carrying a metric attribute. Nothing post-processes it,
 * so no proxy wraps it and the timer would never record: the scan refuses it.
 */
class UnstereotypedService
{
    #[Timed('orders.place')]
    public function place(): void {}
}
