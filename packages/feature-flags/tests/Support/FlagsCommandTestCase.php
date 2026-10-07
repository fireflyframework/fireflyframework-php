<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Tests\Support;

use Firefly\FeatureFlags\FeatureFlagsServiceProvider;
use Firefly\FeatureFlags\FeatureFlagsWiringProvider;
use Firefly\Testing\FireflyTestCase;
use OpenFeature\implementation\provider\NoOpProvider;
use OpenFeature\OpenFeatureAPI;

abstract class FlagsCommandTestCase extends FireflyTestCase
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
            'firefly.feature-flags.flags' => [
                ...ManagementFixtures::flags(),
                'user-audience' => [
                    'state' => 'ENABLED',
                    'variants' => ['context' => 'context', 'option' => 'option', 'other' => 'other'],
                    'defaultVariant' => 'other',
                    'targeting' => ['if' => [
                        ['==' => [['var' => 'targetingKey'], 'from-option']], 'option',
                        ['if' => [['==' => [['var' => 'targetingKey'], 'from-context']], 'context', null]],
                    ]],
                ],
            ],
            'firefly.feature-flags.sources.store.enabled' => true,
            'firefly.feature-flags.sources.store.driver' => 'memory',
            'firefly.feature-flags.management.writes' => true,
        ];
    }

    protected function tearDown(): void
    {
        OpenFeatureAPI::getInstance()->setProvider(new NoOpProvider);
        parent::tearDown();
    }
}
