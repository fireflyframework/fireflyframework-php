<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Tests\Support;

use Firefly\Data\DataServiceProvider;
use Firefly\FeatureFlags\FeatureFlagsServiceProvider;
use Firefly\FeatureFlags\FeatureFlagsWiringProvider;
use Firefly\FeatureFlags\Tests\Fixtures\GatedRoutes\BetaController;
use Firefly\Validation\ValidationServiceProvider;
use Firefly\Web\WebServiceProvider;

abstract class GatedRoutesTestCase extends GatedBeansTestCase
{
    protected function fireflyProviders(): array
    {
        return [ValidationServiceProvider::class, WebServiceProvider::class, DataServiceProvider::class, FeatureFlagsServiceProvider::class, FeatureFlagsWiringProvider::class];
    }

    protected function configOverrides(): array
    {
        return [
            ...parent::configOverrides(),
            'firefly.scan.paths' => ['Firefly\\FeatureFlags\\Tests\\Fixtures\\GatedRoutes\\' => dirname(__DIR__).'/Fixtures/GatedRoutes'],
            'firefly.feature-flags.flags' => ['beta-api' => false, 'checkout-flow' => 'v2'],
            'firefly.feature-flags.events.evaluations' => true,
        ];
    }

    protected function setUp(): void
    {
        BetaController::$calls = 0;
        parent::setUp();
    }
}
