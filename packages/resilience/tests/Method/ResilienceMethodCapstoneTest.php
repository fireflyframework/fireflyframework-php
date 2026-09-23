<?php

declare(strict_types=1);

use Firefly\Data\Proxy\ProxyPlan;
use Firefly\Resilience\Method\ResilienceAdviceSource;
use Firefly\Resilience\Method\ResilienceMethodInterceptor;
use Firefly\Resilience\ResilienceRegistry;
use Firefly\Resilience\Tests\Fixtures\Method\PaymentService;
use Firefly\Resilience\Tests\Support\ResilienceMethodCapstoneTestCase;

uses(ResilienceMethodCapstoneTestCase::class);

/*
 | The six attributes through the REAL pipeline: the boot here is the one a developer's first `artisan serve`
 | takes — scan paths pointed at the fixtures, a cache directory holding nothing — and nothing below is wired
 | by hand. The app scan registers PaymentService as a definition; DataAutoConfiguration::proxyPlan() collects
 | ResilienceAdviceSource beside Data's own TransactionalAdviceSource through Container::getAll() (which
 | depends on the shipped components manifest row declaring the AdviceSource interface); ProxyMaterializer
 | generates the proxy in-process; TransactionalBeanPostProcessor hands it out for a plain #[Service]; and
 | InterceptorRegistry resolves the resilienceMethodInterceptor #[Bean]. A green
 | ResilienceMethodInterceptorTest says nothing about any of that — and an attribute that reaches no proxy is
 | a guard an operator believes is there and is not.
 */

it('proxies a #[Service] carrying resilience attributes on the real boot', function () {
    /** @var ResilienceMethodCapstoneTestCase $this */
    $service = $this->app()->make(PaymentService::class);
    $context = $this->fireflyContext();

    /** @var ProxyPlan $plan */
    $plan = $context->get(ProxyPlan::class);

    expect($service)->toBeInstanceOf(PaymentService::class)
        ->and($service::class)->toBe(PaymentService::class.ProxyPlan::PROXY_SUFFIX)
        ->and(array_keys($plan->adviceFor(PaymentService::class)))->toBe([ResilienceAdviceSource::ID])
        ->and(array_keys($plan->methodsFor(PaymentService::class)))->toBe(['charge', 'refund'])
        ->and($context->get(ResilienceMethodInterceptor::class))->toBeInstanceOf(ResilienceMethodInterceptor::class);
});

it('runs the fallback after the retry gives up, and trips the breaker on the way', function () {
    /** @var ResilienceMethodCapstoneTestCase $this */
    /** @var PaymentService $service */
    $service = $this->app()->make(PaymentService::class);

    expect($service->charge('acct-1', 500))->toBe('queued');

    /** @var ResilienceRegistry $registry */
    $registry = $this->app()->make(ResilienceRegistry::class);

    // 'open' lower-case: CircuitBreaker::OPEN is private, and the string it holds is what every reader of the
    // state (the actuator gauge, the resilience_circuit_breaker_state metric) already compares against. The
    // breaker is open because the FIRST attempt's failure reached it — which is only true while Retry sits
    // outside it, the boundary ResilienceCompositionOrderTest pins on a transcript.
    expect($registry->circuitBreaker('payments')->state())->toBe('open');
});

it('leaves an unannotated method of the same bean alone', function () {
    /** @var ResilienceMethodCapstoneTestCase $this */
    /** @var PaymentService $service */
    $service = $this->app()->make(PaymentService::class);

    expect($service->refund('r-1'))->toBe('r-1');

    // untouched() carries nothing at all and the plan names it nowhere, so the proxy never overrode it:
    // calling it is the proof that a row for one method of a class does not change what its neighbours do.
    $service->untouched();
});
