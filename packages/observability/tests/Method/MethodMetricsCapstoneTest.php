<?php

declare(strict_types=1);

use Firefly\Data\Proxy\ProxyPlan;
use Firefly\Observability\Method\ObservabilityAdviceSource;
use Firefly\Observability\Method\ObservabilityMethodInterceptor;
use Firefly\Observability\Metrics\MeterRegistry;
use Firefly\Observability\Tests\Fixtures\Method\TimedService;
use Firefly\Observability\Tests\Support\MethodMetricsCapstoneTestCase;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;

uses(MethodMetricsCapstoneTestCase::class);

/*
 | #[Timed]/#[Counted]/#[Observed] through the REAL pipeline: the boot here is the one a developer's first
 | `artisan serve` takes — scan paths pointed at the fixtures, a cache directory holding nothing — and
 | nothing below is wired by hand. The app scan registers the fixtures as definitions;
 | DataAutoConfiguration::proxyPlan() collects ObservabilityAdviceSource beside Data's own
 | TransactionalAdviceSource through Container::getAll() (which depends on the shipped components manifest row
 | declaring the AdviceSource interface); ProxyMaterializer generates the proxy in-process;
 | TransactionalBeanPostProcessor hands it out for a plain #[Service]; and InterceptorRegistry resolves the
 | observabilityMethodInterceptor #[Bean]. A green ObservabilityMethodInterceptorTest says nothing about any
 | of that — and a meter that is never recorded reads as "this code is not being called", which is the worst
 | failure this package has.
 */

/**
 * The literal scrape-body accessor, for the reason CapstoneObservabilityIntegrationTest's twin documents:
 * TestResponse::streamedContent() asserts the base response is streamed, and the actuator's never is.
 *
 * @param  TestResponse<Response>  $response
 */
function methodMetricsScrape(TestResponse $response): string
{
    /** @var Response $base */
    $base = $response->baseResponse;

    return (string) $base->getContent();
}

it('plans the metric advice for the annotated fixtures from the scanned AdviceSource beans', function () {
    /** @var MethodMetricsCapstoneTestCase $this */
    /** @var ProxyPlan $plan */
    $plan = $this->fireflyContext()->get(ProxyPlan::class);

    expect($plan->hasProxyFor(TimedService::class))->toBeTrue()
        ->and(array_keys($plan->adviceFor(TimedService::class)))->toBe([ObservabilityAdviceSource::ID])
        ->and(array_keys($plan->methodsFor(TimedService::class)))->toBe(['explode', 'importAll', 'place']);
});

it('wraps a #[Timed] #[Service] in a proxy on the real boot, with the real interceptor bean in its link', function () {
    /** @var MethodMetricsCapstoneTestCase $this */
    $context = $this->fireflyContext();

    expect($this->timedService())->toBeInstanceOf(TimedService::class)
        ->and($this->timedService()::class)->toBe(TimedService::class.ProxyPlan::PROXY_SUFFIX)
        ->and($context->has(ObservabilityMethodInterceptor::class))->toBeTrue()
        ->and($context->get(ObservabilityMethodInterceptor::class))->toBeInstanceOf(ObservabilityMethodInterceptor::class);
});

it('records the timer when the proxied method is called, and scrapes it', function () {
    /** @var MethodMetricsCapstoneTestCase $this */
    expect($this->timedService()->place('SKU-1'))->toBe('SKU-1');

    /** @var MeterRegistry $registry */
    $registry = $this->app()->make(MeterRegistry::class);

    $names = array_map(fn ($meter): string => $meter->name(), $registry->meters());

    expect($names)->toContain('orders.place')
        ->and($registry->timer('orders.place', ['class' => 'TimedService', 'method' => 'place', 'tier' => 'gold', 'exception' => 'none'])->count())->toBe(1);

    $body = methodMetricsScrape($this->get('/actuator/prometheus'));

    expect($body)->toContain('orders_place')->toContain('tier="gold"');
});

it('records the timer tagged with the exception when the method throws, and the exception still reaches the caller', function () {
    /** @var MethodMetricsCapstoneTestCase $this */
    $thrown = null;

    try {
        $this->timedService()->explode();
    } catch (RuntimeException $e) {
        $thrown = $e;
    }

    /** @var MeterRegistry $registry */
    $registry = $this->app()->make(MeterRegistry::class);

    expect($thrown)->toBeInstanceOf(RuntimeException::class)
        ->and($registry->timer('orders.explode', ['class' => 'TimedService', 'method' => 'explode', 'exception' => 'RuntimeException'])->count())->toBe(1);
});

it('counts only the failures of a recordFailuresOnly method and raises its long-task gauge back to zero', function () {
    /** @var MethodMetricsCapstoneTestCase $this */
    expect($this->timedService()->importAll())->toBe(3);

    /** @var MeterRegistry $registry */
    $registry = $this->app()->make(MeterRegistry::class);

    // #[Counted(recordFailuresOnly: true)] on a call that succeeded: nothing counted.
    $counted = array_filter($registry->meters(), fn ($meter): bool => $meter->name() === 'orders.imported');

    // #[Timed(longTask: true)] with no name of its own falls back to the configured default meter name, and
    // its in-flight gauge is back at zero now the call has returned.
    expect($counted)->toBe([])
        ->and($registry->timer('method.timed', ['class' => 'TimedService', 'method' => 'importAll', 'exception' => 'none'])->count())->toBe(1)
        ->and($registry->gauge('method.timed.active', ['class' => 'TimedService', 'method' => 'importAll'], static fn (): float => -1.0)->value())->toBe(0.0);
});
