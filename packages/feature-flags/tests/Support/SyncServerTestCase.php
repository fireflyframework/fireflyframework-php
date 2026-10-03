<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Tests\Support;

use Firefly\FeatureFlags\FeatureFlagsServiceProvider;
use Firefly\FeatureFlags\FeatureFlagsWiringProvider;
use Firefly\Testing\FireflyTestCase;
use OpenFeature\implementation\provider\NoOpProvider;
use OpenFeature\OpenFeatureAPI;

abstract class SyncServerTestCase extends FireflyTestCase
{
    protected function fireflyProviders(): array
    {
        return [FeatureFlagsServiceProvider::class, FeatureFlagsWiringProvider::class];
    }

    protected function configOverrides(): array
    {
        return [
            'cache.default' => 'array',
            'firefly.feature-flags.enabled' => true,
            'firefly.feature-flags.flags' => ['kill-switch' => true],
            'firefly.feature-flags.sources.store.enabled' => true,
            'firefly.feature-flags.sources.store.driver' => 'memory',
            'firefly.feature-flags.server.enabled' => true,
            'firefly.feature-flags.server.token' => 's3cret',
        ];
    }

    protected function tearDown(): void
    {
        OpenFeatureAPI::getInstance()->setProvider(new NoOpProvider);
        parent::tearDown();
    }
}
