<?php

declare(strict_types=1);

namespace Firefly\Container\Tests\Fixtures;

use Firefly\Container\Attributes\Order;
use Firefly\Container\Attributes\Primary;
use Firefly\Container\Attributes\Service;

#[Service]
#[Primary]
#[Order(10)]
final class EnglishGreeter implements Greeter
{
    public function greet(): string
    {
        return 'Hello';
    }
}
