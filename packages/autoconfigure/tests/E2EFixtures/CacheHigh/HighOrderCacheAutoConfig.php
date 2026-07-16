<?php

declare(strict_types=1);

namespace Firefly\AutoConfigure\Tests\E2EFixtures\CacheHigh;

use Firefly\AutoConfigure\Tests\E2EFixtures\Contracts\CachePort;
use Firefly\AutoConfigure\Tests\E2EFixtures\Contracts\HighCache;
use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;

#[Configuration]
#[Order(1010)]
final class HighOrderCacheAutoConfig
{
    #[Bean]
    #[ConditionalOnMissingBean(CachePort::class)]
    public function highCache(): CachePort
    {
        return new HighCache;
    }
}
