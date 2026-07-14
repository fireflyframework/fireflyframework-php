<?php

declare(strict_types=1);

namespace Firefly\Container\Tests\Fixtures;

use Firefly\Container\Attributes\Order;
use Firefly\Container\Attributes\Service;

#[Service]
#[Order(5)]
final class LoudGreeter implements Greeter
{
    public function greet(): string
    {
        return 'HELLO!';
    }
}
