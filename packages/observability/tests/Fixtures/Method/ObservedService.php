<?php

declare(strict_types=1);

namespace Firefly\Observability\Tests\Fixtures\Method;

use Firefly\Container\Attributes\Service;
use Firefly\Observability\Method\Observed;

#[Service]
class ObservedService
{
    #[Observed(name: 'orders.ship', contextualName: 'ship order', lowCardinalityKeyValues: ['carrier' => 'dhl'])]
    public function ship(): string
    {
        return 'shipped';
    }
}
