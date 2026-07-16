<?php

declare(strict_types=1);

namespace Firefly\AutoConfigure\Tests\E2EFixtures\PropX;

use Firefly\AutoConfigure\Tests\E2EFixtures\Contracts\DefaultX;
use Firefly\AutoConfigure\Tests\E2EFixtures\Contracts\XPort;
use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;

#[Configuration]
#[Order(1000)]
#[ConditionalOnProperty('firefly.feature.x.enabled', havingValue: 'true')]
final class PropertyGatedAutoConfig
{
    #[Bean]
    public function x(): XPort
    {
        return new DefaultX;
    }
}
