<?php

declare(strict_types=1);

use Firefly\Data\Proxy\ProxyPlan;
use Firefly\Observability\Method\ObservabilityAdviceSource;
use Firefly\Observability\Method\ObservabilityMethodInterceptor;
use Firefly\Observability\Metrics\MeterRegistry;
use Firefly\Observability\Tests\Fixtures\Method\TimedService;
use Firefly\Observability\Tests\Support\MethodMetricsDisabledCapstoneTestCase;

uses(MethodMetricsDisabledCapstoneTestCase::class);

/*
 | The switched-off boot. The PLAN is a compiled artifact and does not move with configuration, so the class is
 | planned and proxied exactly as before — what changes is that the interceptor #[Bean] is conditioned away and
 | InterceptorRegistry substitutes a PassThroughInterceptor. Turning the key off must therefore be inert, not
 | half-enforced and not a boot failure, and this is the only place that difference is observable: a unit test
 | constructs the interceptor it tests and can never watch it be absent.
 */

it('still plans and proxies the annotated fixture when the key is off', function () {
    /** @var MethodMetricsDisabledCapstoneTestCase $this */
    /** @var ProxyPlan $plan */
    $plan = $this->fireflyContext()->get(ProxyPlan::class);

    expect($plan->hasProxyFor(TimedService::class))->toBeTrue()
        ->and(array_keys($plan->adviceFor(TimedService::class)))->toBe([ObservabilityAdviceSource::ID])
        ->and($this->timedService()::class)->toBe(TimedService::class.ProxyPlan::PROXY_SUFFIX);
});

it('conditions the interceptor bean away and records nothing through the pass-through link', function () {
    /** @var MethodMetricsDisabledCapstoneTestCase $this */
    expect($this->fireflyContext()->has(ObservabilityMethodInterceptor::class))->toBeFalse()
        ->and($this->timedService()->place('SKU-1'))->toBe('SKU-1');

    /** @var MeterRegistry $registry */
    $registry = $this->app()->make(MeterRegistry::class);

    $names = array_map(fn ($meter): string => $meter->name(), $registry->meters());

    expect($names)->not->toContain('orders.place');
});
