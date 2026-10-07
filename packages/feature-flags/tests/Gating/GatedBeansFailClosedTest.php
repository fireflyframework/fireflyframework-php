<?php

declare(strict_types=1);

use Firefly\FeatureFlags\Gating\FeatureFlagDisabledException;
use Firefly\FeatureFlags\Tests\Fixtures\GatedBeans\CheckoutService;
use Firefly\FeatureFlags\Tests\Support\GatedBeansDisabledTestCase;

uses(GatedBeansDisabledTestCase::class);

it('fails every gate closed while firefly.feature-flags.enabled is off', function (): void {
    /** @var GatedBeansDisabledTestCase $this */
    /** @var CheckoutService $service */
    $service = $this->app()->make(CheckoutService::class);

    expect(fn () => $service->checkout('cart-1'))->toThrow(FeatureFlagDisabledException::class)
        ->and($service->price(100))->toBe('legacy:100')
        ->and($service->optOut())->toBe('ran');
});
