<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Container\Container as FireflyContainer;
use Firefly\Container\Scanner\ComponentManifest;
use Firefly\FeatureFlags\FeatureFlags;
use Firefly\FeatureFlags\Gating\FeatureFlagGate;
use Firefly\FeatureFlags\Tests\Support\GateFixtures;
use Firefly\Kernel\Error\ErrorCategory;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;

it('opens only for an enabled flag or its selected variant', function (): void {
    $gate = GateFixtures::gate();

    expect($gate->allows('on'))->toBeTrue()
        ->and($gate->allows('off'))->toBeFalse()
        ->and($gate->allows('flow', 'v2'))->toBeTrue()
        ->and($gate->allows('flow', 'v1'))->toBeFalse();
});

it('uses the caller default for missing, disabled, mistyped and unresolved flags', function (): void {
    $gate = GateFixtures::gate();

    foreach (['missing', 'paused', 'unset'] as $key) {
        expect($gate->allows($key, 'v2'))->toBeFalse()
            ->and($gate->allows($key, 'v2', true))->toBeTrue();
    }

    expect($gate->allows('missing'))->toBeFalse()
        ->and($gate->allows('missing', default: true))->toBeTrue()
        ->and($gate->allows('paused', default: true))->toBeTrue()
        ->and($gate->allows('flow'))->toBeFalse();
});

it('uses the caller default if the subsystem is unbound', function (): void {
    $gate = GateFixtures::gate(withFlags: false);

    expect($gate->allows('on'))->toBeFalse()
        ->and($gate->allows('on', default: true))->toBeTrue()
        ->and($gate->allows('on', 'v2'))->toBeFalse()
        ->and($gate->allows('on', 'v2', true))->toBeTrue();
});

it('uses the caller default if the flags bean cannot be resolved', function (): void {
    $illuminate = new Container;
    $illuminate->bind(FeatureFlags::class, static fn (): never => throw new RuntimeException('unavailable'));
    $gate = new FeatureFlagGate(new FireflyContainer($illuminate, new ComponentManifest([])), new Config(new Repository));

    expect($gate->allows('on'))->toBeFalse()
        ->and($gate->allows('on', default: true))->toBeTrue()
        ->and($gate->allows('on', 'v2'))->toBeFalse()
        ->and($gate->allows('on', 'v2', true))->toBeTrue();
});

it('maps configured disabled status without exposing the key', function (int $status, string $code, ErrorCategory $category): void {
    $exception = GateFixtures::gate(settings: ['web' => ['disabled-status' => $status]])->disabled('secret-launch');

    expect($exception->httpStatus())->toBe($status)
        ->and($exception->errorCode())->toBe($code)
        ->and($exception->category())->toBe($category)
        ->and($exception->getMessage())->not->toContain('secret-launch')
        ->and($exception->flagKey())->toBe('secret-launch');
})->with([
    [404, 'RESOURCE_NOT_FOUND', ErrorCategory::Business],
    [403, 'ACCESS_DENIED', ErrorCategory::Security],
    [503, 'SERVICE_UNAVAILABLE', ErrorCategory::Infrastructure],
]);

it('validates disabled status when the gate is constructed', function (): void {
    expect(fn () => GateFixtures::gate(settings: ['web' => ['disabled-status' => 500]]))
        ->toThrow(ConfigurationException::class, 'must be 404, 403 or 503');
});
