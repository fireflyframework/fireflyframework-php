<?php

declare(strict_types=1);

namespace Firefly\Observability\Tests\Fixtures\FinalService;

use Firefly\Container\Attributes\Service;
use Firefly\Observability\Method\Timed;

/**
 * A stereotyped bean the advice would have to proxy while being `final`: a proxy must extend it, so the scan
 * refuses the rule rather than let it compile into a plan nothing can apply.
 */
#[Service]
final class FinalTimedService
{
    #[Timed('orders.place')]
    public function place(): void {}
}
