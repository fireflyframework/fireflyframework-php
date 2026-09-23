<?php

declare(strict_types=1);

namespace Firefly\Observability\Tests\Fixtures\Method;

use Firefly\Container\Attributes\Service;
use Firefly\Observability\Method\Counted;
use Firefly\Observability\Method\Timed;

#[Service]
class TimedService
{
    #[Timed('orders.place', extraTags: ['tier' => 'gold'], description: 'Places an order.')]
    public function place(string $sku): string
    {
        return $sku;
    }

    #[Timed(longTask: true)]
    #[Counted('orders.imported', recordFailuresOnly: true)]
    public function importAll(): int
    {
        return 3;
    }

    public function untouched(): void {}
}
