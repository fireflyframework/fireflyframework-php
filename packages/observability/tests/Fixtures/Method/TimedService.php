<?php

declare(strict_types=1);

namespace Firefly\Observability\Tests\Fixtures\Method;

use Firefly\Container\Attributes\Service;
use Firefly\Observability\Method\Counted;
use Firefly\Observability\Method\Timed;
use RuntimeException;

#[Service]
class TimedService
{
    #[Timed('orders.place', extraTags: ['tier' => 'gold'])]
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

    /**
     * The throwing half of the capstone: the timer must still be recorded, tagged with the class that came
     * out of the method rather than with `none`, and the exception must reach the caller unchanged.
     */
    #[Timed('orders.explode')]
    public function explode(): never
    {
        throw new RuntimeException('boom');
    }

    public function untouched(): void {}
}
