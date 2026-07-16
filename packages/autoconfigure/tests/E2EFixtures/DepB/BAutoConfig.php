<?php

declare(strict_types=1);

namespace Firefly\AutoConfigure\Tests\E2EFixtures\DepB;

use Firefly\AutoConfigure\Tests\E2EFixtures\Contracts\APort;
use Firefly\AutoConfigure\Tests\E2EFixtures\Contracts\BPort;
use Firefly\AutoConfigure\Tests\E2EFixtures\Contracts\DefaultB;
use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnBean;

#[Configuration]
#[Order(1010)]
final class BAutoConfig
{
    #[Bean]
    #[ConditionalOnBean(APort::class)]
    public function b(): BPort
    {
        return new DefaultB;
    }
}
