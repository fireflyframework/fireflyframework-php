<?php

declare(strict_types=1);

use Firefly\Context\Boot\ApplicationContext;
use Firefly\FeatureFlags\FeatureFlagsServiceProvider;
use Firefly\FeatureFlags\FeatureFlagsSettings;
use Firefly\FeatureFlags\FeatureFlagsWiringProvider;

it('boots green when discovered alongside the bootstrap provider and stays dark while disabled', function (): void {
    $app = fireflyApplication(
        ['firefly' => []],
        [FeatureFlagsServiceProvider::class, FeatureFlagsWiringProvider::class],
        needs: ['cache'],
    );

    expect($app->make(ApplicationContext::class))->toBeInstanceOf(ApplicationContext::class)
        ->and($app->bound(FeatureFlagsSettings::class))->toBeFalse();
});

it('binds the settings once firefly.feature-flags.enabled is on', function (): void {
    $context = bootFireflyApp(
        ['firefly' => ['feature-flags' => ['enabled' => true]]],
        [FeatureFlagsServiceProvider::class, FeatureFlagsWiringProvider::class],
        needs: ['cache'],
    );

    /** @var FeatureFlagsSettings $settings */
    $settings = $context->get(FeatureFlagsSettings::class);

    expect($settings->enabled)->toBeTrue();
});
