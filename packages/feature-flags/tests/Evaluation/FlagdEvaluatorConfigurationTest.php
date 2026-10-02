<?php

declare(strict_types=1);

use Firefly\FeatureFlags\Evaluation\DefaultFlagdEvaluator;
use Firefly\FeatureFlags\Evaluation\FlagdEvaluator;
use Firefly\FeatureFlags\FeatureFlagsServiceProvider;
use Firefly\FeatureFlags\FeatureFlagsWiringProvider;
use OpenFeature\implementation\provider\NoOpProvider;
use OpenFeature\OpenFeatureAPI;

// A boot with flags on installs Firefly's provider in the process-wide OpenFeature API once the lanes merge:
// put the no-op provider back so no later test inherits it.
afterEach(fn () => OpenFeatureAPI::getInstance()->setProvider(new NoOpProvider));

it('wires the in-process flagd evaluator when feature flags are enabled', function (): void {
    $context = bootFireflyApp(
        ['firefly' => ['feature-flags' => ['enabled' => true]]],
        [FeatureFlagsServiceProvider::class, FeatureFlagsWiringProvider::class],
        needs: ['cache'],
    );

    expect($context->get(FlagdEvaluator::class))->toBeInstanceOf(DefaultFlagdEvaluator::class);
});

it('registers no evaluator while feature flags are disabled', function (): void {
    $app = fireflyApplication(
        ['firefly' => []],
        [FeatureFlagsServiceProvider::class, FeatureFlagsWiringProvider::class],
        needs: ['cache'],
    );

    expect($app->bound(FlagdEvaluator::class))->toBeFalse();
});
