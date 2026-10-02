<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\FeatureFlags\Definition\InvalidFlagDefinition;
use Firefly\FeatureFlags\FeatureFlagsSettings;
use Firefly\FeatureFlags\Source\ConfigFlagSource;
use Firefly\FeatureFlags\Source\FlagSource;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Illuminate\Config\Repository;

/** @param array<string, mixed> $section */
function featureFlagsConfigSource(array $section): ConfigFlagSource
{
    return new ConfigFlagSource(FeatureFlagsSettings::fromConfig(new Config(new Repository(['firefly' => ['feature-flags' => $section]]))));
}

it('is the lowest, always-checked layer that refuses the boot', function (): void {
    $source = featureFlagsConfigSource([]);

    expect($source->name())->toBe(FlagSource::CONFIG)
        ->and($source->precedence())->toBe(100)
        ->and($source->refreshInterval())->toBe(0.0)
        ->and($source->failsStartup())->toBeTrue()
        ->and($source->reportedRevision('abc'))->toBeNull();
});

it('normalizes shorthand, keeps evaluators and reports an unchanged revision as null', function (): void {
    $source = featureFlagsConfigSource([
        'flags' => ['kill-switch' => true, 'checkout-flow' => 'v2'],
        'evaluators' => ['is-beta' => ['in' => ['beta', ['var' => 'roles']]]],
    ]);

    $snapshot = $source->load(null);

    expect($snapshot)->not->toBeNull()
        ->and($snapshot?->document->flag('kill-switch')?->defaultVariant())->toBe('on')
        ->and($snapshot?->document->flag('checkout-flow')?->variantNames())->toBe(['v2'])
        ->and(array_keys($snapshot?->document->evaluators ?? []))->toBe(['is-beta'])
        ->and($source->load($snapshot?->revision))->toBeNull();
});

it('loads again under a configuration that differs, and only then', function (): void {
    $before = featureFlagsConfigSource(['flags' => ['kill-switch' => true]])->load(null);
    $same = featureFlagsConfigSource(['flags' => ['kill-switch' => true]]);
    $after = featureFlagsConfigSource(['flags' => ['kill-switch' => false]]);

    expect($same->load($before?->revision))->toBeNull()
        ->and($after->load($before?->revision)?->document->flag('kill-switch')?->defaultVariant())->toBe('off');
});

it('refuses an invalid inline definition with its key and the contract phrase', function (): void {
    expect(fn () => featureFlagsConfigSource(['flags' => ['bad key' => true]])->load(null))
        ->toThrow(InvalidFlagDefinition::class, 'invalid flag key');
});

it('reads an empty PHP array as an empty object: PHP configuration cannot write {}', function (): void {
    $document = featureFlagsConfigSource(['flags' => [], 'evaluators' => []])->load(null)?->document;

    expect($document?->keys())->toBe([])
        ->and($document?->evaluators)->toBe([]);
});

it('refuses a non-empty list where the flags or the evaluators object belongs', function (string $section, string $key, string $reason): void {
    $refusal = null;
    try {
        featureFlagsConfigSource([$section => [['var' => 'a']]])->load(null);
    } catch (InvalidFlagDefinition $caught) {
        $refusal = $caught;
    }

    expect([$refusal?->flagKey(), $refusal?->reason()])->toBe([$key, $reason]);
})->with([
    'flags' => ['flags', 'flags', 'flags must be an object'],
    'evaluators' => ['evaluators', '$evaluators', '$evaluators must be an object'],
]);

it('never sees a flags or evaluators value that is not an array: the settings refuse it first, naming the key', function (string $section): void {
    expect(fn () => featureFlagsConfigSource([$section => 'new-checkout']))
        ->toThrow(ConfigurationException::class, "[firefly.feature-flags.{$section}] must be array");
})->with(['flags', 'evaluators']);
