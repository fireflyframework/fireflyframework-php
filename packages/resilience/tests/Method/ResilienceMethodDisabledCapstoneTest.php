<?php

declare(strict_types=1);

use Firefly\Data\Proxy\ProxyPlan;
use Firefly\Resilience\Method\ResilienceAdviceSource;
use Firefly\Resilience\Method\ResilienceMethodInterceptor;
use Firefly\Resilience\ResilienceRegistry;
use Firefly\Resilience\Tests\Fixtures\Method\PaymentService;
use Firefly\Resilience\Tests\Support\ResilienceMethodDisabledCapstoneTestCase;

uses(ResilienceMethodDisabledCapstoneTestCase::class);

/*
 | The switched-off boot. The PLAN is a compiled artifact and does not move with configuration, so the class
 | is planned and proxied exactly as before — what changes is that the interceptor #[Bean] is conditioned away
 | and InterceptorRegistry substitutes a PassThroughInterceptor. Turning the key off must therefore be inert:
 | not half-applied (a retry without its fallback, a fallback without its breaker) and not a boot failure.
 */

it('still plans and proxies the guarded fixture when the key is off', function () {
    /** @var ResilienceMethodDisabledCapstoneTestCase $this */
    /** @var ProxyPlan $plan */
    $plan = $this->fireflyContext()->get(ProxyPlan::class);

    expect($plan->hasProxyFor(PaymentService::class))->toBeTrue()
        ->and(array_keys($plan->adviceFor(PaymentService::class)))->toBe([ResilienceAdviceSource::ID])
        ->and($this->app()->make(PaymentService::class)::class)->toBe(PaymentService::class.ProxyPlan::PROXY_SUFFIX);
});

it('conditions the interceptor bean away and applies no pattern at all through the pass-through link', function () {
    /** @var ResilienceMethodDisabledCapstoneTestCase $this */
    /** @var PaymentService $service */
    $service = $this->app()->make(PaymentService::class);

    // The method's own failure reaches the caller: no retry, and — the half-applied shape that would be worst
    // — no fallback quietly turning the outage into 'queued'.
    expect(static fn (): string => $service->charge('acct-1', 500))->toThrow(RuntimeException::class, 'gateway down')
        ->and($this->fireflyContext()->has(ResilienceMethodInterceptor::class))->toBeFalse();

    /** @var ResilienceRegistry $registry */
    $registry = $this->app()->make(ResilienceRegistry::class);

    // And nothing was recorded on the way: the breaker never saw a call, so it is still closed.
    expect($registry->circuitBreaker('payments')->state())->toBe('closed');
});
