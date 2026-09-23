<?php

declare(strict_types=1);

use Firefly\Data\Proxy\ProxyPlan;
use Firefly\Resilience\ResilienceRegistry;
use Firefly\Resilience\Tests\Fixtures\ClassLevelFallback\PaymentGateway;
use Firefly\Resilience\Tests\Support\ResilienceClassLevelFallbackCapstoneTestCase;

uses(ResilienceClassLevelFallbackCapstoneTestCase::class);

/*
 | THE RECOVERY IS NOT AN ADVICE TARGET, through the real proxy. A class-level #[CircuitBreaker] plus a
 | method-level #[Fallback] is the commonest resilience shape an application writes, and it is the one in
 | which the scanner's class-level fan-out and the interceptor's `getThis()` recovery call meet: the recovery
 | is invoked ON THE BEAN, which is the proxy, so a row fanned onto it would be applied by the same link that
 | is unwinding. The breaker would have opened on the failure the fallback exists to absorb, and would then
 | refuse the recovery with CircuitBreakerOpenException — raised from inside the catch that was handling the
 | outage, the one moment a fallback must not fail.
 |
 | ResilienceMethodScannerTest pins the rows; this pins what the rows DO, which is the half a unit test
 | cannot reach: the re-entry only happens because the generated proxy overrides a planned method, and only a
 | real proxy generates.
 */

it('leaves the recovery out of the plan, so the proxy never overrides it', function () {
    /** @var ResilienceClassLevelFallbackCapstoneTestCase $this */
    $context = $this->fireflyContext();

    /** @var ProxyPlan $plan */
    $plan = $context->get(ProxyPlan::class);

    // `charge` is planned (the class's breaker reached it, and it carries the #[Fallback]); the recovery is
    // NOT. Without the scanner's shield this array is ['charge', 'chargeUnavailable'] and the assertion below
    // about the returned value throws instead.
    expect($plan->hasProxyFor(PaymentGateway::class))->toBeTrue()
        ->and(array_keys($plan->methodsFor(PaymentGateway::class)))->toBe(['charge']);
});

it('returns the degraded answer even though the class-level breaker is OPEN by the time the recovery runs', function () {
    /** @var ResilienceClassLevelFallbackCapstoneTestCase $this */
    /** @var PaymentGateway $gateway */
    $gateway = $this->app()->make(PaymentGateway::class);

    expect($gateway::class)->toBe(PaymentGateway::class.ProxyPlan::PROXY_SUFFIX)
        ->and($gateway->charge('acct-1'))->toBe('queued:acct-1')
        // The method ran once and its failure reached the breaker. One attempt is enough:
        // `circuit-breaker.payments.failure-threshold => 1`.
        ->and($gateway->calls)->toBe(1);

    /** @var ResilienceRegistry $registry */
    $registry = $this->app()->make(ResilienceRegistry::class);

    // …and this is what makes the assertion above mean something. The breaker is OPEN — it tripped on the
    // very failure the fallback absorbed — so a recovery that were guarded by it would have been REFUSED
    // rather than run. 'queued:acct-1' came back while the breaker was in the state that would have refused
    // it, which is only possible because the recovery is not an advice target.
    expect($registry->circuitBreaker('payments')->state())->toBe('open');
});
