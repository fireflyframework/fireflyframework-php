<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Data\Proxy\MethodInvocation;
use Firefly\Observability\Method\ObservabilityMethodDescriptor;
use Firefly\Observability\Method\ObservabilityMethodInterceptor;
use Firefly\Observability\Metrics\MetricsRecorder;
use Firefly\Observability\Metrics\SimpleMeterRegistry;
use Firefly\Observability\Tracing\NoOpTracer;
use Firefly\Observability\Tracing\Span;
use Firefly\Observability\Tracing\SpanContext;
use Firefly\Observability\Tracing\SpanKind;
use Firefly\Observability\Tracing\SpanStatus;
use Firefly\Observability\Tracing\Tracer;
use Firefly\Testing\Double\RecordingTracer;
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
 * is the write port under test and the read port the assertions use — which is why the parameter is the
 * narrow MetricsRecorder rather than the registry: the failure cases below hand it one that only throws.
 *
 * The tracer is injectable and defaults to NoOpTracer, because the span half of #[Observed] is half of what
 * the attribute exists for and a NoOp asserts nothing about it.
 */
function metricsInterceptor(MetricsRecorder $registry, bool $enabled = true, ?Tracer $tracer = null): ObservabilityMethodInterceptor
{
    return new ObservabilityMethodInterceptor(
        $registry,
        $tracer ?? new NoOpTracer,
        new Config(new Repository(['firefly' => ['observability' => ['method' => ['enabled' => $enabled]]]])),
    );
}

/**
 * A recorder whose every write fails — the cache-backed registry with its store unreachable, which is the
 * failure mode `firefly.observability.metrics.store` makes possible on EVERY annotated method. Named for
 * this file because one Pest process shares its global class names.
 */
final class ExplodingMethodMetricsRecorder implements MetricsRecorder
{
    /** @param array<string, string> $tags */
    public function increment(string $name, array $tags = [], float $amount = 1.0): void
    {
        throw new RuntimeException('metrics store unreachable');
    }

    /** @param array<string, string> $tags */
    public function record(string $name, array $tags = [], float $seconds = 0.0): void
    {
        throw new RuntimeException('metrics store unreachable');
    }

    /** @param array<string, string> $tags */
    public function setGauge(string $name, array $tags, float $value): void
    {
        throw new RuntimeException('metrics store unreachable');
    }
}

/**
 * One port, one failure: a registry that records timers and gauges normally and refuses only the counter —
 * which is what SimpleMeterRegistry itself does to a #[Counted] whose name is already a timer's. It stands in
 * for every partial registry failure (a collision, one unreachable cache key) that a guard shared by all three
 * record*() calls would have turned into the loss of the meters behind it.
 */
final class FailingCounterMetricsRecorder implements MetricsRecorder
{
    public function __construct(private readonly MetricsRecorder $delegate) {}

    /** @param array<string, string> $tags */
    public function increment(string $name, array $tags = [], float $amount = 1.0): void
    {
        throw new InvalidArgumentException("Metric '{$name}' already registered as timer; cannot re-register as counter.");
    }

    /** @param array<string, string> $tags */
    public function record(string $name, array $tags = [], float $seconds = 0.0): void
    {
        $this->delegate->record($name, $tags, $seconds);
    }

    /** @param array<string, string> $tags */
    public function setGauge(string $name, array $tags, float $value): void
    {
        $this->delegate->setGauge($name, $tags, $value);
    }
}

/** The tracer half of the same hazard: an exporter that cannot start a span. */
final class ExplodingMethodMetricsTracer implements Tracer
{
    /** @param array<string, bool|int|float|string|array<mixed>|null> $attributes */
    public function startSpan(string $name, SpanKind $kind = SpanKind::Internal, array $attributes = [], ?SpanContext $parent = null): Span
    {
        throw new RuntimeException('tracer unreachable');
    }

    public function currentSpan(): ?Span
    {
        return null;
    }

    /**
     * @template T
     *
     * @param  callable(Span): T  $callback
     * @param  array<string, bool|int|float|string|array<mixed>|null>  $attributes
     * @return T
     */
    public function trace(string $name, callable $callback, SpanKind $kind = SpanKind::Internal, array $attributes = []): mixed
    {
        throw new RuntimeException('tracer unreachable');
    }
}

/**
 * The long-task gauge's current reading. The fallback supplier is never the one that answers — setGauge()
 * registered the meter on entry — so a -1.0 here means "no gauge was ever published", which is a distinct
 * failure from "the gauge says zero".
 *
 * @param  array<string, string>  $tags
 */
function metricsGauge(SimpleMeterRegistry $registry, string $name, array $tags): float
{
    return $registry->gauge($name, $tags, static fn (): float => -1.0)->value();
}

it('records a timer around a successful call, tagged exception=none', function (): void {
    $registry = new SimpleMeterRegistry;
    $descriptor = new ObservabilityMethodDescriptor('App\\Orders\\OrderService', 'place', ['name' => 'orders.place', 'tags' => ['tier' => 'gold'], 'longTask' => false]);

    $result = metricsInterceptor($registry)->invoke(metricsInvocation($descriptor, static fn (): string => 'ok'));

    $timer = $registry->timer('orders.place', ['class' => 'OrderService', 'method' => 'place', 'tier' => 'gold', 'exception' => 'none']);

    expect($result)->toBe('ok')->and($timer->count())->toBe(1);
});

it('records the timer tagged with the exception class and rethrows', function (): void {
    $registry = new SimpleMeterRegistry;
    $descriptor = new ObservabilityMethodDescriptor('App\\Orders\\OrderService', 'place', ['name' => 'orders.place', 'tags' => [], 'longTask' => false]);

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
    $descriptor = new ObservabilityMethodDescriptor('App\\Orders\\OrderService', 'importAll', ['name' => 'orders.import', 'tags' => [], 'longTask' => true]);

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
    $descriptor = new ObservabilityMethodDescriptor('App\\Orders\\OrderService', 'place', ['name' => 'orders.place', 'tags' => [], 'longTask' => false]);

    expect(metricsInterceptor($registry, enabled: false)->invoke(metricsInvocation($descriptor, static fn (): string => 'ok')))->toBe('ok')
        ->and($registry->meters())->toBe([]);
});

/*
 | THE LONG-TASK GAUGE. #[Timed]'s docblock promises "the number of invocations THIS PROCESS has in flight",
 | and the only coverage this used to have was "it is back at 0 after one completed call" — which a 1/0 flag
 | passes just as well as a depth counter does. These two read the gauge WHILE work is in flight, which is
 | the only moment the two semantics differ, and pin the tag set the gauge is published under.
 */

it('counts the DEPTH of a re-entered long task rather than raising a 1/0 flag', function (): void {
    $registry = new SimpleMeterRegistry;
    $interceptor = metricsInterceptor($registry);
    $descriptor = new ObservabilityMethodDescriptor('App\\Orders\\OrderService', 'importAll', ['name' => 'orders.import', 'tags' => [], 'longTask' => true]);

    $tags = ['class' => 'OrderService', 'method' => 'importAll'];

    /** @var list<float> $readings */
    $readings = [];

    // The inner call is the self-invocation a generated proxy really performs: the proxy is a SUBCLASS, so a
    // `$this->importAll()` inside the body dispatches through this same link a second time.
    $inner = static function () use ($registry, $tags, &$readings): string {
        $readings[] = metricsGauge($registry, 'orders.import.active', $tags);

        return 'inner';
    };

    $outer = static function () use ($interceptor, $descriptor, $registry, $tags, $inner, &$readings): string {
        $readings[] = metricsGauge($registry, 'orders.import.active', $tags);
        $interceptor->invoke(metricsInvocation($descriptor, $inner));
        $readings[] = metricsGauge($registry, 'orders.import.active', $tags);

        return 'outer';
    };

    expect($interceptor->invoke(metricsInvocation($descriptor, $outer)))->toBe('outer')
        // 1 inside the outer call, 2 inside the nested one, back to 1 when the nested one returned — a flag
        // would read 1, 1 and then 0 while the outer invocation was still running.
        ->and($readings)->toBe([1.0, 2.0, 1.0])
        ->and(metricsGauge($registry, 'orders.import.active', $tags))->toBe(0.0);
});

it('publishes the long-task gauge under the TIMER\'s tags, extraTags included', function (): void {
    $registry = new SimpleMeterRegistry;
    $descriptor = new ObservabilityMethodDescriptor('App\\Orders\\OrderService', 'importAll', ['name' => 'orders.import', 'tags' => ['shard' => 'eu'], 'longTask' => true]);

    metricsInterceptor($registry)->invoke(metricsInvocation($descriptor, static fn (): string => 'ok'));

    $gauges = array_values(array_map(
        static fn ($meter): array => $meter->tags(),
        array_filter($registry->meters(), static fn ($meter): bool => $meter->name() === 'orders.import.active'),
    ));

    // Exactly ONE gauge, carrying the same tags as the timer it belongs to — not a second series under the
    // bare class/method pair that no query could join to `orders.import`.
    expect($gauges)->toBe([['class' => 'OrderService', 'method' => 'importAll', 'shard' => 'eu']]);
});

/*
 | THE SPAN HALF OF #[Observed] — half of what the attribute exists for ("ONE name that starts BOTH a span
 | and a timer"), and until these tests the only tracer that ever walked it was NoOpTracer, which records
 | nothing and therefore asserts nothing.
 */

it('starts an INTERNAL span under the contextualName, with the metric tags as attributes, and ends it', function (): void {
    $registry = new SimpleMeterRegistry;
    $tracer = new RecordingTracer;
    $descriptor = new ObservabilityMethodDescriptor('App\\Orders\\OrderService', 'ship', null, null, ['name' => 'orders.ship', 'contextualName' => 'ship order', 'tags' => ['carrier' => 'dhl']]);

    metricsInterceptor($registry, tracer: $tracer)->invoke(metricsInvocation($descriptor, static fn (): string => 'shipped'));

    $span = $tracer->recorded()[0];

    expect($tracer->recorded())->toHaveCount(1)
        ->and($span->name)->toBe('ship order')
        ->and($span->kind)->toBe(SpanKind::Internal)
        ->and($span->attributes)->toBe(['class' => 'OrderService', 'method' => 'ship', 'carrier' => 'dhl'])
        ->and($span->status)->toBe(SpanStatus::Unset)
        ->and($span->ended)->toBeTrue();
});

it('names the span after the METRIC when the attribute gives no contextualName', function (): void {
    $registry = new SimpleMeterRegistry;
    $tracer = new RecordingTracer;
    $descriptor = new ObservabilityMethodDescriptor('App\\Orders\\OrderService', 'ship', null, null, ['name' => 'orders.ship', 'contextualName' => '', 'tags' => []]);

    metricsInterceptor($registry, tracer: $tracer)->invoke(metricsInvocation($descriptor, static fn (): string => 'shipped'));

    expect($tracer->recorded()[0]->name)->toBe('orders.ship');
});

it('records the exception on the span and marks it ERROR before ending it', function (): void {
    $registry = new SimpleMeterRegistry;
    $tracer = new RecordingTracer;
    $descriptor = new ObservabilityMethodDescriptor('App\\Orders\\OrderService', 'ship', null, null, ['name' => 'orders.ship', 'contextualName' => '', 'tags' => []]);

    $call = fn (): mixed => metricsInterceptor($registry, tracer: $tracer)->invoke(metricsInvocation($descriptor, static fn (): never => throw new RuntimeException('no carrier')));

    expect($call)->toThrow(RuntimeException::class, 'no carrier');

    $span = $tracer->recorded()[0];

    expect($span->status)->toBe(SpanStatus::Error)
        ->and($span->statusDescription)->toBe('no carrier')
        ->and($span->exception?->getMessage())->toBe('no carrier')
        ->and($span->ended)->toBeTrue();
});

/*
 | TELEMETRY NEVER CHANGES THE CALL. Every write below runs in a `finally`, where a throw DISCARDS the
 | exception already on its way to the caller — so an unguarded recorder turns a caught-and-handled domain
 | exception into a metrics exception no `catch` in the application matches, and every successful #[Timed]
 | method into a throw. HttpExchangeFilter::record() carries the same guard for the same reason.
 */

it('returns the method\'s value even when every metric write fails', function (): void {
    $descriptor = new ObservabilityMethodDescriptor('App\\Orders\\OrderService', 'importAll', ['name' => 'orders.import', 'tags' => [], 'longTask' => true], ['name' => 'orders.counted', 'tags' => [], 'failuresOnly' => false]);

    $result = metricsInterceptor(new ExplodingMethodMetricsRecorder)->invoke(metricsInvocation($descriptor, static fn (): string => 'ok'));

    expect($result)->toBe('ok');
});

it('lets the method\'s own exception through unchanged when every metric write fails', function (): void {
    $descriptor = new ObservabilityMethodDescriptor('App\\Orders\\OrderService', 'place', ['name' => 'orders.place', 'tags' => [], 'longTask' => false]);

    $call = fn (): mixed => metricsInterceptor(new ExplodingMethodMetricsRecorder)->invoke(metricsInvocation($descriptor, static fn (): never => throw new OutOfRangeException('out of stock')));

    // The DOMAIN exception, not 'metrics store unreachable' — the swap a throw from the `finally` would make.
    expect($call)->toThrow(OutOfRangeException::class, 'out of stock');
});

it('runs the method even when the tracer cannot start a span', function (): void {
    $registry = new SimpleMeterRegistry;
    $descriptor = new ObservabilityMethodDescriptor('App\\Orders\\OrderService', 'ship', null, null, ['name' => 'orders.ship', 'contextualName' => '', 'tags' => []]);

    $result = metricsInterceptor($registry, tracer: new ExplodingMethodMetricsTracer)->invoke(metricsInvocation($descriptor, static fn (): string => 'shipped'));

    // …and the timer half still recorded: a tracing failure costs the span, nothing else.
    expect($result)->toBe('shipped')
        ->and($registry->timer('orders.ship', ['class' => 'OrderService', 'method' => 'ship', 'exception' => 'none'])->count())->toBe(1);
});

/*
 | …and a guard PER METER, not one around all three. The three share a registry but not a fate: a registry
 | rejects a single meter identity and goes on answering for every other, and SimpleMeterRegistry does exactly
 | that on purpose — guardType() refuses a name already registered under another type, because a Prometheus
 | name carries one `# TYPE`. Under one shared guard the FIRST such refusal discarded the record calls behind
 | it, so an #[Observed] with a name of its own and no collision of its own silently never existed either.
 */

it('records the timer and the observation even when the counter\'s write throws', function (): void {
    $registry = new SimpleMeterRegistry;
    $descriptor = new ObservabilityMethodDescriptor(
        'App\\Orders\\OrderService',
        'place',
        ['name' => 'orders.place', 'tags' => [], 'longTask' => false],
        ['name' => 'orders.counted', 'tags' => [], 'failuresOnly' => false],
        ['name' => 'orders.ship', 'contextualName' => '', 'tags' => []],
    );

    $result = metricsInterceptor(new FailingCounterMetricsRecorder($registry))->invoke(metricsInvocation($descriptor, static fn (): string => 'ok'));

    // The counter is the one lost sample; the timer BEFORE it and the observation AFTER it both recorded.
    expect($result)->toBe('ok')
        ->and($registry->timer('orders.place', ['class' => 'OrderService', 'method' => 'place', 'exception' => 'none'])->count())->toBe(1)
        ->and($registry->timer('orders.ship', ['class' => 'OrderService', 'method' => 'place', 'exception' => 'none'])->count())->toBe(1);
});

it('keeps the observation when the registry refuses a REAL #[Timed]/#[Counted] name collision', function (): void {
    $registry = new SimpleMeterRegistry;

    // The shape the scanner now refuses at `firefly:cache` — reproduced here from a hand-built descriptor,
    // because a plan compiled before that refusal existed can still load and must not cost `orders.ship`.
    $descriptor = new ObservabilityMethodDescriptor(
        'App\\Orders\\OrderService',
        'place',
        ['name' => 'orders.place', 'tags' => [], 'longTask' => false],
        ['name' => 'orders.place', 'tags' => [], 'failuresOnly' => false],
        ['name' => 'orders.ship', 'contextualName' => '', 'tags' => []],
    );

    metricsInterceptor($registry)->invoke(metricsInvocation($descriptor, static fn (): string => 'ok'));

    $names = array_map(static fn ($meter): string => $meter->name(), $registry->meters());
    sort($names);

    // Two meters, not one: the counter is the only casualty of its own collision.
    expect($names)->toBe(['orders.place', 'orders.ship']);
});
