<?php

declare(strict_types=1);

use Firefly\FeatureFlags\Definition\InvalidFlagDefinition;
use Firefly\FeatureFlags\Definition\Json;
use Firefly\FeatureFlags\FeatureFlags;
use Firefly\FeatureFlags\FeatureFlagsServiceProvider;
use Firefly\FeatureFlags\FeatureFlagsWiringProvider;
use Firefly\FeatureFlags\Registry\FlagRegistry;
use Firefly\Testing\FeatureFlags\FeatureFlagOverrides;
use Illuminate\Foundation\Application;
use OpenFeature\implementation\provider\NoOpProvider;
use OpenFeature\OpenFeatureAPI;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

afterEach(fn () => OpenFeatureAPI::getInstance()->setProvider(new NoOpProvider));

/** @param array<string, mixed> $featureFlags */
function featureFlagsTestingApp(array $featureFlags): Application
{
    return fireflyApplication(
        ['firefly' => ['feature-flags' => $featureFlags]],
        [FeatureFlagsServiceProvider::class, FeatureFlagsWiringProvider::class],
        [LoggerInterface::class => new NullLogger],
        needs: ['cache'],
    );
}

it('overrides the running application with shorthand and full definitions until cleared', function (): void {
    $app = featureFlagsTestingApp(['enabled' => true, 'flags' => ['new-checkout' => false]]);
    /** @var FeatureFlags $flags */
    $flags = $app->make(FeatureFlags::class);
    /** @var FlagRegistry $registry */
    $registry = $app->make(FlagRegistry::class);

    $overrides = withFeatureFlags(['new-checkout' => true, 'checkout-flow' => 'v2']);
    expect($flags->isEnabled('new-checkout'))->toBeTrue()
        ->and($flags->getString('checkout-flow', 'v1'))->toBe('v2');

    $overrides->set('full', ['state' => 'ENABLED', 'variants' => ['ready' => 'ready'], 'defaultVariant' => 'ready']);
    expect($flags->getString('full', 'missing'))->toBe('ready')
        ->and($registry->composition()->flag('new-checkout')?->origin)->toBe('test-overrides')
        ->and($registry->composition(false)->flag('new-checkout')?->origin)->toBe('config');

    $overrides->forget('new-checkout');
    expect($flags->isEnabled('new-checkout'))->toBeFalse()
        ->and($overrides->flags())->toHaveKeys(['checkout-flow', 'full']);

    $overrides->clear();
    expect($flags->getString('checkout-flow', 'v1'))->toBe('v1')
        ->and($registry->testOverrides())->toBeNull()
        ->and($overrides->flags())->toBe([]);
});

it('keeps every override for the rest of the test across calls', function (): void {
    $app = featureFlagsTestingApp(['enabled' => true]);
    /** @var FlagRegistry $registry */
    $registry = $app->make(FlagRegistry::class);

    $first = withFeatureFlags(['first' => true]);
    $second = withFeatureFlags(['second' => 'v2']);

    expect($second)->toBe($first)
        ->and($first->flags())->toBe(['first' => true, 'second' => 'v2'])
        ->and($registry->document()->flag('first'))->not->toBeNull()
        ->and($registry->document()->flag('second'))->not->toBeNull();
});

it('rejects invalid changes without losing the last valid override set', function (): void {
    $app = featureFlagsTestingApp(['enabled' => true]);
    /** @var FlagRegistry $registry */
    $registry = $app->make(FlagRegistry::class);
    $overrides = withFeatureFlags(['valid' => true]);
    $before = $registry->testOverrides();

    expect(fn () => $overrides->merge(['also-valid' => false, 'bad key' => true]))
        ->toThrow(InvalidFlagDefinition::class, 'invalid flag key');
    expect($overrides->flags())->toBe(['valid' => true])
        ->and($registry->testOverrides())->toBe($before)
        ->and($registry->document()->flag('also-valid'))->toBeNull();

    expect(fn () => $overrides->set('valid', 7))->toThrow(InvalidFlagDefinition::class);
    expect($overrides->flags())->toBe(['valid' => true]);
});

it('reports the contract error for definitions deeper than the snapshot limit without changing overrides', function (): void {
    $app = featureFlagsTestingApp(['enabled' => true]);
    /** @var FlagRegistry $registry */
    $registry = $app->make(FlagRegistry::class);
    $overrides = withFeatureFlags(['valid' => true]);
    $before = $registry->testOverrides();

    $nested = [];
    for ($level = 0; $level < 520; $level++) {
        $nested = ['child' => $nested];
    }
    $definition = ['state' => 'ENABLED', 'variants' => ['v' => $nested], 'defaultVariant' => 'v'];

    $error = null;
    try {
        $overrides->set('deep', $definition);
    } catch (InvalidFlagDefinition $caught) {
        $error = $caught;
    }

    expect($error)->toBeInstanceOf(InvalidFlagDefinition::class)
        ->and($error?->flagKey())->toBe('deep')
        ->and($error?->reason())->toBe('definition nests too deeply')
        ->and($overrides->flags())->toBe(['valid' => true])
        ->and($registry->testOverrides())->toBe($before)
        ->and($registry->document()->flag('valid'))->not->toBeNull()
        ->and($registry->document()->flag('deep'))->toBeNull();
});

it('owns caller definitions and getter snapshots across unrelated merges', function (): void {
    $app = featureFlagsTestingApp(['enabled' => true]);
    /** @var FlagRegistry $registry */
    $registry = $app->make(FlagRegistry::class);
    $definition = (object) ['state' => 'ENABLED', 'variants' => ['on' => true, 'off' => false], 'defaultVariant' => 'on'];
    $overrides = withFeatureFlags(['a' => $definition]);

    $definition->defaultVariant = 'off';
    expect($registry->document()->flag('a')?->defaultVariant())->toBe('on');

    $snapshot = $overrides->flags();
    $returned = $snapshot['a'];
    if (! $returned instanceof stdClass) {
        throw new LogicException('Expected the full definition to remain an object.');
    }
    $returned->state = 'INVALID';
    $overrides->set('b', true);

    expect($registry->document()->flag('a')?->defaultVariant())->toBe('on')
        ->and($registry->document()->flag('a')?->state())->toBe('ENABLED')
        ->and(Json::members($overrides->flags()['a'])['state'])->toBe('ENABLED')
        ->and($registry->document()->flag('b'))->not->toBeNull();
});

it('owns nested JSON objects without changing their object and list shapes', function (): void {
    $app = featureFlagsTestingApp(['enabled' => true]);
    /** @var FlagRegistry $registry */
    $registry = $app->make(FlagRegistry::class);
    $value = (object) ['empty' => (object) [], 'items' => []];
    $overrides = withFeatureFlags(['object' => ['state' => 'ENABLED', 'variants' => ['v' => $value], 'defaultVariant' => 'v']]);

    $value->empty->changed = true;
    $value->items[] = 'changed';
    expect(Json::encode($registry->document()->flag('object')?->variantValue('v')))->toBe('{"empty":{},"items":[]}');

    $snapshot = $overrides->flags();
    $returned = Json::members(Json::members($snapshot['object'])['variants'])['v'];
    if (! $returned instanceof stdClass || ! $returned->empty instanceof stdClass) {
        throw new LogicException('Expected the nested variant and empty member to remain objects.');
    }
    $returned->empty->changed = true;
    $overrides->set('other', false);

    expect(Json::encode($registry->document()->flag('object')?->variantValue('v')))->toBe('{"empty":{},"items":[]}')
        ->and(Json::encode(Json::members(Json::members($overrides->flags()['object'])['variants'])['v']))->toBe('{"empty":{},"items":[]}');
});

it('isolates overrides between application registries', function (): void {
    $firstApp = featureFlagsTestingApp(['enabled' => true]);
    $first = FeatureFlagOverrides::install(['first' => true], $firstApp);
    $secondApp = featureFlagsTestingApp(['enabled' => true]);
    $second = FeatureFlagOverrides::install(['second' => true], $secondApp);
    /** @var FlagRegistry $firstRegistry */
    $firstRegistry = $firstApp->make(FlagRegistry::class);
    /** @var FlagRegistry $secondRegistry */
    $secondRegistry = $secondApp->make(FlagRegistry::class);

    expect($second)->not->toBe($first)
        ->and($firstRegistry->document()->flag('first'))->not->toBeNull()
        ->and($firstRegistry->document()->flag('second'))->toBeNull()
        ->and($secondRegistry->document()->flag('second'))->not->toBeNull()
        ->and($secondRegistry->document()->flag('first'))->toBeNull();
});

it('explains when the application has no flag registry', function (): void {
    featureFlagsTestingApp(['enabled' => false]);

    expect(fn () => withFeatureFlags(['a' => true]))->toThrow(LogicException::class, 'firefly.feature-flags.enabled');
});
