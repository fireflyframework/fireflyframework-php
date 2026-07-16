<?php

declare(strict_types=1);

namespace Firefly\AutoConfigure\Tests\E2EFixtures\CacheLow;

use Firefly\AutoConfigure\Tests\E2EFixtures\Contracts\CachePort;
use Firefly\AutoConfigure\Tests\E2EFixtures\Contracts\LowCache;
use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;

#[Configuration]
#[Order(1000)]
final class LowOrderCacheAutoConfig
{
    #[Bean]
    #[ConditionalOnMissingBean(CachePort::class)]
    public function lowCache(): CachePort
    {
        return new LowCache;
    }
}
