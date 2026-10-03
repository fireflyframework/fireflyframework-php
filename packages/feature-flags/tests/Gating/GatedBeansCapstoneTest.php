<?php

declare(strict_types=1);

use Firefly\Data\Proxy\ProxyPlan;
use Firefly\FeatureFlags\Gating\FeatureFlagAdviceSource;
use Firefly\FeatureFlags\Gating\FeatureFlagDisabledException;
use Firefly\FeatureFlags\Tests\Fixtures\GatedBeans\CheckoutService;
use Firefly\FeatureFlags\Tests\Fixtures\GatedBeans\ReportService;
use Firefly\FeatureFlags\Tests\Fixtures\GatedBeans\VirtualFallbackService;
use Firefly\FeatureFlags\Tests\Support\GatedBeansTestCase;

uses(GatedBeansTestCase::class);

it('proxies a #[Service] carrying #[FeatureFlag] on the real uncached boot', function (): void {
    /** @var GatedBeansTestCase $this */
    $service = $this->app()->make(CheckoutService::class);
    /** @var ProxyPlan $plan */
    $plan = $this->fireflyContext()->get(ProxyPlan::class);

    expect($service::class)->toBe(CheckoutService::class.ProxyPlan::PROXY_SUFFIX)
        ->and(array_keys($plan->adviceFor(CheckoutService::class)))->toBe([FeatureFlagAdviceSource::ID]);
});

it('refuses a call while the flag is off and runs it once it is on', function (): void {
    /** @var GatedBeansTestCase $this */
    /** @var CheckoutService $service */
    $service = $this->app()->make(CheckoutService::class);

    expect(fn () => $service->checkout('cart-1'))->toThrow(FeatureFlagDisabledException::class)
        ->and($service->untouched())->toBe('free');

    $this->overrideFlags(['new-checkout' => true]);

    expect($service->checkout('cart-1'))->toBe('new:cart-1');
});

it('answers with the fallback while off', function (): void {
    /** @var GatedBeansTestCase $this */
    /** @var CheckoutService $service */
    $service = $this->app()->make(CheckoutService::class);

    expect($service->price(100))->toBe('legacy:100');

    $this->overrideFlags(['new-pricing' => true]);

    expect($service->price(100))->toBe('new:100');
});

it('gates every public method of a class-level #[FeatureFlag]', function (): void {
    /** @var GatedBeansTestCase $this */
    /** @var ReportService $reports */
    $reports = $this->app()->make(ReportService::class);
    $this->overrideFlags(['reports' => false]);

    expect(fn () => $reports->daily())->toThrow(FeatureFlagDisabledException::class)
        ->and(fn () => $reports->weekly())->toThrow(FeatureFlagDisabledException::class);
});

it('honours default: true for a flag nobody defined', function (): void {
    /** @var GatedBeansTestCase $this */
    /** @var CheckoutService $service */
    $service = $this->app()->make(CheckoutService::class);

    expect($service->optOut())->toBe('ran');
});

it('uses the concrete override for a class-level named fallback', function (): void {
    /** @var GatedBeansTestCase $this */
    /** @var VirtualFallbackService $service */
    $service = $this->app()->make(VirtualFallbackService::class);
    $this->overrideFlags(['reports' => false]);

    expect($service->run('x'))->toBe('child:x');
});
