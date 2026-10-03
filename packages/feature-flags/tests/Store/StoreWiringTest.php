<?php

declare(strict_types=1);

use Firefly\FeatureFlags\FeatureFlagsServiceProvider;
use Firefly\FeatureFlags\FeatureFlagsWiringProvider;
use Firefly\FeatureFlags\Registry\FlagRegistry;
use Firefly\FeatureFlags\Store\FlagStore;
use Firefly\FeatureFlags\Store\FlagStoreWriter;
use Firefly\FeatureFlags\Store\MemoryFlagStore;
use OpenFeature\implementation\provider\NoOpProvider;
use OpenFeature\OpenFeatureAPI;

afterEach(fn () => OpenFeatureAPI::getInstance()->setProvider(new NoOpProvider));

it('wires the memory store as the highest source and the writer over it', function (): void {
    $context = bootFireflyApp(['firefly' => ['feature-flags' => [
        'enabled' => true,
        'flags' => ['kill-switch' => true],
        'sources' => ['store' => ['enabled' => true, 'driver' => 'memory']],
    ]]], [FeatureFlagsServiceProvider::class, FeatureFlagsWiringProvider::class], needs: ['cache']);

    /** @var FlagStoreWriter $writer */
    $writer = $context->get(FlagStoreWriter::class);
    $writer->put('kill-switch', ['state' => 'ENABLED', 'variants' => ['on' => true, 'off' => false], 'defaultVariant' => 'off'], 'ops');
    /** @var FlagRegistry $registry */
    $registry = $context->get(FlagRegistry::class);

    expect($context->get(FlagStore::class))->toBeInstanceOf(MemoryFlagStore::class)
        ->and(array_map(static fn ($state): string => $state->name, $registry->states()))->toBe(['config', 'store'])
        ->and($registry->composition()->flag('kill-switch')?->origin)->toBe('store')
        ->and($registry->composition()->flag('kill-switch')?->overrides)->toBe(['config']);
    $context->close();
});

it('wires no store while sources.store.enabled is off', function (): void {
    $app = fireflyApplication(['firefly' => ['feature-flags' => ['enabled' => true]]], [FeatureFlagsServiceProvider::class, FeatureFlagsWiringProvider::class], needs: ['cache']);

    expect($app->bound(FlagStore::class))->toBeFalse()
        ->and($app->bound(FlagStoreWriter::class))->toBeFalse();
});
