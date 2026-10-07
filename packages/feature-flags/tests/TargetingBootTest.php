<?php

declare(strict_types=1);

use Firefly\FeatureFlags\Evaluation\DefaultFlagdEvaluator;
use Firefly\FeatureFlags\Evaluation\FlagdEvaluator;
use Firefly\FeatureFlags\FeatureFlags;
use Firefly\FeatureFlags\FeatureFlagsServiceProvider;
use Firefly\FeatureFlags\FeatureFlagsWiringProvider;
use OpenFeature\implementation\provider\NoOpProvider;
use OpenFeature\OpenFeatureAPI;

afterEach(fn () => OpenFeatureAPI::getInstance()->setProvider(new NoOpProvider));

it('evaluates targeting, shared evaluators and rollouts with the real evaluator on a real boot', function (): void {
    $context = bootFireflyApp(['firefly' => ['feature-flags' => [
        'enabled' => true,
        'evaluators' => ['is-beta' => ['in' => ['beta', ['var' => 'roles']]]],
        'flags' => [
            'new-checkout' => ['state' => 'ENABLED', 'variants' => ['on' => true, 'off' => false], 'defaultVariant' => 'off',
                'targeting' => ['if' => [['$ref' => 'is-beta'], 'on', null]]],
            'rollout-10' => ['state' => 'ENABLED', 'variants' => ['on' => true, 'off' => false], 'defaultVariant' => 'off',
                'targeting' => ['fractional' => [['on', 10], ['off', 90]]]],
        ],
    ]]], [FeatureFlagsServiceProvider::class, FeatureFlagsWiringProvider::class], needs: ['cache']);
    /** @var FeatureFlags $flags */
    $flags = $context->get(FeatureFlags::class);

    expect($context->get(FlagdEvaluator::class))->toBeInstanceOf(DefaultFlagdEvaluator::class)
        ->and($flags->isEnabled('new-checkout', context: ['roles' => ['beta']]))->toBeTrue()
        ->and($flags->isEnabled('new-checkout', context: ['roles' => ['staff']]))->toBeFalse()
        // user-4 is inside the 10% bucket of rollout-10 in the Firefly vectors; user-42 is not.
        ->and($flags->isEnabled('rollout-10', targetingKey: 'user-4'))->toBeTrue()
        ->and($flags->isEnabled('rollout-10', targetingKey: 'user-42'))->toBeFalse()
        ->and($flags->details('rollout-10', false)->reason)->toBe('DEFAULT');
});
