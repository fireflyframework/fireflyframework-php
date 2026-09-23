<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Data\Proxy\MethodInvocation;
use Firefly\Observability\Method\ObservabilityMethodDescriptor;
use Firefly\Observability\Method\ObservabilityMethodInterceptor;
use Firefly\Observability\Metrics\SimpleMeterRegistry;
use Firefly\Observability\Tracing\NoOpTracer;
use Illuminate\Config\Repository;

/**
 * A single-link invocation over a hand-built descriptor: the interceptor is the ONLY link, so proceed() lands
 * straight in the terminal closure. The declared class and the method name come from the descriptor itself,
 * because those two are exactly what the compiled row and the generated proxy agree on — and they are what
 * the `class`/`method` tags are read from. The descriptor map is keyed by class exactly as the generated
 * proxy keys it, which is what makes `descriptor(ObservabilityMethodDescriptor::class)` find it.
 *
 * @param  array<mixed>  $args
 * @param  callable(mixed...): mixed  $terminal
 */
function metricsInvocation(ObservabilityMethodDescriptor $descriptor, callable $terminal, array $args = []): MethodInvocation
{
    return new MethodInvocation(
        new stdClass,
        $descriptor->class,
        $descriptor->method,
        array_values($args),
        [],
        [ObservabilityMethodDescriptor::class => $descriptor],
        static fn (array $arguments): mixed => $terminal(...$arguments),
    );
}

/**
 * SimpleMeterRegistry IS the recorder (it implements MeterRegistry and MetricsRecorder both), so one object
 * is the write port under test and the read port the assertions use.
 */
function metricsInterceptor(SimpleMeterRegistry $registry, bool $enabled = true): ObservabilityMethodInterceptor
{
    return new ObservabilityMethodInterceptor(
        $registry,
        new NoOpTracer,
        new Config(new Repository(['firefly' => ['observability' => ['method' => ['enabled' => $enabled]]]])),
    );
}

it('records a timer around a successful call, tagged exception=none', function (): void {
    $registry = new SimpleMeterRegistry;
    $descriptor = new ObservabilityMethodDescriptor('App\\Orders\\OrderService', 'place', ['name' => 'orders.place', 'tags' => ['tier' => 'gold'], 'description' => '', 'longTask' => false]);

    $result = metricsInterceptor($registry)->invoke(metricsInvocation($descriptor, static fn (): string => 'ok'));

    $timer = $registry->timer('orders.place', ['class' => 'OrderService', 'method' => 'place', 'tier' => 'gold', 'exception' => 'none']);

    expect($result)->toBe('ok')->and($timer->count())->toBe(1);
});

it('records the timer tagged with the exception class and rethrows', function (): void {
    $registry = new SimpleMeterRegistry;
    $descriptor = new ObservabilityMethodDescriptor('App\\Orders\\OrderService', 'place', ['name' => 'orders.place', 'tags' => [], 'description' => '', 'longTask' => false]);

    $call = fn (): mixed => metricsInterceptor($registry)->invoke(metricsInvocation($descriptor, static fn (): never => throw new RuntimeException('boom')));

    expect($call)->toThrow(RuntimeException::class, 'boom');
    expect($registry->timer('orders.place', ['class' => 'OrderService', 'method' => 'place', 'exception' => 'RuntimeException'])->count())->toBe(1);
});

it('counts every invocation, and only the failures under recordFailuresOnly', function (): void {
    $registry = new SimpleMeterRegistry;
    $all = new ObservabilityMethodDescriptor('App\\Orders\\OrderService', 'place', null, ['name' => 'orders.counted', 'tags' => [], 'failuresOnly' => false]);
    $failures = new ObservabilityMethodDescriptor('App\\Orders\\OrderService', 'place', null, ['name' => 'orders.failed', 'tags' => [], 'failuresOnly' => true]);

    metricsInterceptor($registry)->invoke(metricsInvocation($all, static fn (): string => 'ok'));
    metricsInterceptor($registry)->invoke(metricsInvocation($failures, static fn (): string => 'ok'));

    expect($registry->counter('orders.counted', ['class' => 'OrderService', 'method' => 'place', 'result' => 'success', 'exception' => 'none'])->count())->toBe(1.0)
        ->and($registry->counter('orders.failed', ['class' => 'OrderService', 'method' => 'place', 'result' => 'success', 'exception' => 'none'])->count())->toBe(0.0);
});

it('counts a refusal as a failure, tagged with the exception it threw', function (): void {
    $registry = new SimpleMeterRegistry;
    $descriptor = new ObservabilityMethodDescriptor('App\\Orders\\OrderService', 'place', null, ['name' => 'orders.counted', 'tags' => [], 'failuresOnly' => true]);

    $call = fn (): mixed => metricsInterceptor($registry)->invoke(metricsInvocation($descriptor, static fn (): never => throw new RuntimeException('denied')));

    expect($call)->toThrow(RuntimeException::class, 'denied');
    expect($registry->counter('orders.counted', ['class' => 'OrderService', 'method' => 'place', 'result' => 'failure', 'exception' => 'RuntimeException'])->count())->toBe(1.0);
});

it('publishes an <meter>.active gauge for a long task and returns it to zero', function (): void {
    $registry = new SimpleMeterRegistry;
    $descriptor = new ObservabilityMethodDescriptor('App\\Orders\\OrderService', 'importAll', ['name' => 'orders.import', 'tags' => [], 'description' => '', 'longTask' => true]);

    metricsInterceptor($registry)->invoke(metricsInvocation($descriptor, static fn (): int => 3));

    $gauge = $registry->gauge('orders.import.active', ['class' => 'OrderService', 'method' => 'importAll'], static fn (): float => 0.0);

    expect($gauge->value())->toBe(0.0);
});

it('records an #[Observed] as one timer under its own name', function (): void {
    $registry = new SimpleMeterRegistry;
    $descriptor = new ObservabilityMethodDescriptor('App\\Orders\\OrderService', 'ship', null, null, ['name' => 'orders.ship', 'contextualName' => 'ship order', 'tags' => ['carrier' => 'dhl']]);

    metricsInterceptor($registry)->invoke(metricsInvocation($descriptor, static fn (): string => 'shipped'));

    expect($registry->timer('orders.ship', ['class' => 'OrderService', 'method' => 'ship', 'carrier' => 'dhl', 'exception' => 'none'])->count())->toBe(1);
});

it('does nothing at all when the master key is off', function (): void {
    $registry = new SimpleMeterRegistry;
    $descriptor = new ObservabilityMethodDescriptor('App\\Orders\\OrderService', 'place', ['name' => 'orders.place', 'tags' => [], 'description' => '', 'longTask' => false]);

    expect(metricsInterceptor($registry, enabled: false)->invoke(metricsInvocation($descriptor, static fn (): string => 'ok')))->toBe('ok')
        ->and($registry->meters())->toBe([]);
});
