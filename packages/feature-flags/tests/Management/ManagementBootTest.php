<?php

declare(strict_types=1);

use Firefly\Context\Boot\ApplicationContext;
use Firefly\FeatureFlags\Event\FeatureFlagEvaluated;
use Firefly\FeatureFlags\FeatureFlags;
use Firefly\FeatureFlags\FeatureFlagsServiceProvider;
use Firefly\FeatureFlags\FeatureFlagsWiringProvider;
use Firefly\FeatureFlags\Management\FlagManagement;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Application;
use OpenFeature\implementation\provider\NoOpProvider;
use OpenFeature\OpenFeatureAPI;

afterEach(fn () => OpenFeatureAPI::getInstance()->setProvider(new NoOpProvider));

/** @param array<string, mixed> $featureFlags */
function featureFlagsManagementApp(array $featureFlags): Application
{
    return fireflyApplication(
        ['app' => ['name' => 'shop'], 'firefly' => [
            'feature-flags' => $featureFlags,
            'scan' => ['paths' => ['Firefly\\FeatureFlags\\Tests\\Fixtures\\AmbientContext\\' => dirname(__DIR__).'/Fixtures/AmbientContext']],
        ]],
        [FeatureFlagsServiceProvider::class, FeatureFlagsWiringProvider::class],
        needs: ['cache'],
    );
}

it('wires FlagManagement only while the subsystem is enabled', function (): void {
    expect(featureFlagsManagementApp(['enabled' => true])->make(ApplicationContext::class)->get(FlagManagement::class))->toBeInstanceOf(FlagManagement::class)
        ->and(featureFlagsManagementApp(['enabled' => false])->bound(FlagManagement::class))->toBeFalse();
});

it('previews with the explicit context and the application attributes only, recording no exposure (I-3)', function (): void {
    $app = featureFlagsManagementApp([
        'enabled' => true,
        'events' => ['evaluations' => true],
        'flags' => [
            'tiered' => ['state' => 'ENABLED', 'variants' => ['gold' => 'gold', 'base' => 'base'], 'defaultVariant' => 'base',
                'targeting' => ['if' => [['==' => [['var' => 'tier'], 'gold']], 'gold', null]]],
            'shop-only' => ['state' => 'ENABLED', 'variants' => ['on' => true, 'off' => false], 'defaultVariant' => 'off',
                'targeting' => ['if' => [['==' => [['var' => 'application'], 'shop']], 'on', null]]],
        ],
    ]);
    $exposures = 0;
    $app->make(Dispatcher::class)->listen(FeatureFlagEvaluated::class, static function () use (&$exposures): void {
        $exposures++;
    });
    $context = $app->make(ApplicationContext::class);
    /** @var FeatureFlags $flags */
    $flags = $context->get(FeatureFlags::class);
    /** @var FlagManagement $management */
    $management = $context->get(FlagManagement::class);

    $ambient = $flags->getString('tiered', 'x');
    $previews = [
        $management->evaluate('tiered')['value'],
        $management->evaluate('tiered', ['tier' => 'gold'])['value'],
        $management->evaluate('shop-only')['value'],
    ];

    expect($ambient)->toBe('gold')
        ->and($previews)->toBe(['base', 'gold', true])
        ->and($exposures)->toBe(1);
});
