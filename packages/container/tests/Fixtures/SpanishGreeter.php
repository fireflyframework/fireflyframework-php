<?php

declare(strict_types=1);

namespace Firefly\Container\Tests\Fixtures;

use Firefly\Container\Attributes\Order;
use Firefly\Container\Attributes\Qualifier;
use Firefly\Container\Attributes\Service;

#[Service('spanish')]
#[Qualifier('spanish')]
#[Order(20)]
final class SpanishGreeter implements Greeter
{
    public function greet(): string
    {
        return 'Hola';
    }
}
