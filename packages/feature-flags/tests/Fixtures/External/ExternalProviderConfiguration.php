<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Tests\Fixtures\External;

use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\FeatureFlags\Tests\Support\FixedProvider;
use OpenFeature\interfaces\provider\Provider;

#[Configuration]
final class ExternalProviderConfiguration
{
    #[Bean]
    public function vendorProvider(): Provider
    {
        return new FixedProvider;
    }
}
