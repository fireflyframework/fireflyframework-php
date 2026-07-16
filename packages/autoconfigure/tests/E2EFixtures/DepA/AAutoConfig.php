<?php

declare(strict_types=1);

namespace Firefly\AutoConfigure\Tests\E2EFixtures\DepA;

use Firefly\AutoConfigure\Tests\E2EFixtures\Contracts\APort;
use Firefly\AutoConfigure\Tests\E2EFixtures\Contracts\DefaultA;
use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;

#[Configuration]
#[Order(1000)]
#[ConditionalOnProperty('firefly.a.enabled', havingValue: 'true')]
final class AAutoConfig
{
    #[Bean]
    public function a(): APort
    {
        return new DefaultA;
    }
}
