<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Tests\Support;

use Firefly\Actuator\ActuatorServiceProvider;
use Firefly\Actuator\ActuatorWiringProvider;
use Firefly\FeatureFlags\FeatureFlagsServiceProvider;
use Firefly\FeatureFlags\FeatureFlagsWiringProvider;
use Firefly\Testing\Boot\FireflyBoot;
use Firefly\Testing\FireflyTestCase;
use Firefly\Validation\ValidationServiceProvider;
use Firefly\Web\WebServiceProvider;
use Illuminate\Foundation\Application;
use OpenFeature\implementation\provider\NoOpProvider;
use OpenFeature\OpenFeatureAPI;

/** `/actuator/flags` exposed, a memory store, writes on. */
abstract class ManagementEndpointTestCase extends FireflyTestCase
{
    protected function fireflyProviders(): array
    {
        return [ValidationServiceProvider::class, WebServiceProvider::class, ActuatorServiceProvider::class, ActuatorWiringProvider::class, FeatureFlagsServiceProvider::class, FeatureFlagsWiringProvider::class];
    }

    protected function configOverrides(): array
    {
        return [
            'cache.default' => 'array',
            'firefly.management.enabled' => true,
            'firefly.management.endpoints.web.exposure.include' => 'health,flags',
            'firefly.feature-flags.enabled' => true,
            'firefly.feature-flags.flags' => [
                '0' => true,
                '2024' => 'v2',
                'price' => ['state' => 'ENABLED', 'variants' => ['a' => 1.0, 'b' => 2.5], 'defaultVariant' => 'a'],
            ],
            'firefly.feature-flags.sources.store.enabled' => true,
            'firefly.feature-flags.sources.store.driver' => 'memory',
            'firefly.feature-flags.management.writes' => true,
        ];
    }

    protected function defineFireflyEnvironment(Application $app): void
    {
        FireflyBoot::stubScheduledManifest($app);
    }

    protected function tearDown(): void
    {
        OpenFeatureAPI::getInstance()->setProvider(new NoOpProvider);
        parent::tearDown();
    }
}
