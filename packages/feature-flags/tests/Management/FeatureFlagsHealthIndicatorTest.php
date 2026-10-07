<?php

declare(strict_types=1);

use Firefly\Actuator\Boot\HealthContributorRegistrar;
use Firefly\Actuator\Health\Status;
use Firefly\Container\Container as FireflyContainer;
use Firefly\Container\Scanner\ComponentManifest;
use Firefly\FeatureFlags\Management\FeatureFlagsHealthIndicator;
use Firefly\FeatureFlags\Registry\CacheBook;
use Firefly\FeatureFlags\Registry\FlagRegistry;
use Firefly\FeatureFlags\Source\FlagSourceUnavailable;
use Firefly\FeatureFlags\Tests\Support\RecordingLogger;
use Firefly\FeatureFlags\Tests\Support\StubFlagSource;
use Firefly\Testing\Double\RecordingApplicationEventPublisher;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;

/** @param list<StubFlagSource> $sources */
function featureFlagsHealth(array $sources, ?Closure $today = null): FeatureFlagsHealthIndicator
{
    $illuminate = new Container;
    if ($sources !== []) {
        $illuminate->instance(FlagRegistry::class, new FlagRegistry($sources, new CacheBook(new Repository(new ArrayStore), new RecordingLogger), new RecordingApplicationEventPublisher));
    }

    return new FeatureFlagsHealthIndicator(new FireflyContainer($illuminate, new ComponentManifest([])), $today ?? static fn (): string => '2026-10-01');
}

it('registers as `featureflags`, and only while a Firefly registry exists', function (): void {
    expect(HealthContributorRegistrar::nameFor(FeatureFlagsHealthIndicator::class))->toBe('featureflags')
        ->and(featureFlagsHealth([])->available())->toBeFalse()
        ->and(featureFlagsHealth([new StubFlagSource('config', 100, 0.0, ['a' => true], failsStartup: true)])->available())->toBeTrue();
});

it('is UP with per-source details, the flag count and the expired keys', function (): void {
    $health = featureFlagsHealth([new StubFlagSource('config', 100, 0.0, ['a' => true, 'old' => [
        'state' => 'ENABLED', 'variants' => ['on' => true], 'defaultVariant' => 'on', 'metadata' => ['expires' => '2025-01-01'],
    ]], failsStartup: true)])->health();

    /** @var array{sources: array<string, array{status: string, error: ?string}>, flags: int, expired: list<string>} $details */
    $details = $health->details;

    expect($health->status)->toBe(Status::Up)
        ->and($details['flags'])->toBe(2)
        ->and($details['expired'])->toBe(['old'])
        ->and($details['sources'])->toHaveKey('config');
});

it('is DOWN only while an enabled source has never loaded', function (): void {
    $http = new StubFlagSource('http', 300, 30.0);
    $http->failure = new FlagSourceUnavailable('control plane unreachable');

    $health = featureFlagsHealth([new StubFlagSource('config', 100, 0.0, ['a' => true], failsStartup: true), $http])->health();

    /** @var array{sources: array<string, array{status: string, error: ?string}>} $details */
    $details = $health->details;

    expect($health->status)->toBe(Status::Down)
        ->and($details['sources']['http']['status'])->toBe('DOWN')
        ->and($details['sources']['http']['error'])->toContain('control plane unreachable');
});

it('stays UP with a STALE source serving its last good flags', function (): void {
    $source = new StubFlagSource('http', 300, 0.0, ['still-live' => true]);
    $illuminate = new Container;
    $registry = new FlagRegistry([$source], new CacheBook(new Repository(new ArrayStore), new RecordingLogger), new RecordingApplicationEventPublisher);
    $registry->refresh();
    $source->failure = new FlagSourceUnavailable('control plane unreachable');
    $registry->refresh(force: true);
    $illuminate->instance(FlagRegistry::class, $registry);
    $health = (new FeatureFlagsHealthIndicator(new FireflyContainer($illuminate, new ComponentManifest([])), static fn (): string => '2026-10-01'))->health();
    $sources = $health->details['sources'] ?? null;
    if (! is_array($sources) || ! is_array($sources['http'] ?? null)) {
        throw new RuntimeException('The health response omitted the http source.');
    }

    expect($health->status)->toBe(Status::Up)
        ->and($health->details['flags'])->toBe(1)
        ->and($sources['http']['status'])->toBe('STALE')
        ->and($sources['http']['error'])->toContain('control plane unreachable');
});

it('uses its injected UTC date for expiry', function (): void {
    $source = new StubFlagSource('config', 100, 0.0, ['deadline' => [
        'state' => 'ENABLED', 'variants' => ['on' => true], 'defaultVariant' => 'on', 'metadata' => ['expires' => '2026-10-01'],
    ]]);

    expect(featureFlagsHealth([$source], static fn (): string => '2026-10-01')->health()->details['expired'])->toBe([])
        ->and(featureFlagsHealth([$source], static fn (): string => '2026-10-02')->health()->details['expired'])->toBe(['deadline']);
});
