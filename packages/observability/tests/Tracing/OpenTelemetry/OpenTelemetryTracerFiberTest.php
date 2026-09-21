<?php

declare(strict_types=1);

use Firefly\Observability\Tracing\OpenTelemetry\OpenTelemetryTracer;
use Firefly\Observability\Tracing\SpanKind;
use OpenTelemetry\API\Trace\Span as OtelSpan;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;

/**
 * The OpenTelemetry API keeps one context stack PER FIBER and raises E_USER_WARNING when a fiber reads its
 * context before anything was attached in it — under Laravel's error handler, an ErrorException. A server
 * that hands each request to a fiber (the browser test plugin's in-process AMP server, an Amp or ReactPHP
 * application server) therefore 500ed on the first traced request. These cases run the tracer inside a real
 * Fiber under a handler that turns EVERY warning into an exception, so a regression is a thrown ErrorException
 * and not a silent notice.
 *
 * @return array{0: OpenTelemetryTracer, 1: InMemoryExporter}
 */
function fiberTracer(): array
{
    $exporter = new InMemoryExporter;

    return [new OpenTelemetryTracer(new TracerProvider([new SimpleSpanProcessor($exporter)])), $exporter];
}

/**
 * @template T
 *
 * @param  callable(): T  $callback
 * @return T
 */
function insideFiberWithWarningsFatal(callable $callback): mixed
{
    set_error_handler(static function (int $level, string $message): never {
        throw new ErrorException($message, 0, $level);
    });

    try {
        $fiber = new Fiber($callback);
        $fiber->start();

        return $fiber->getReturn();
    } finally {
        restore_error_handler();
    }
}

it('starts a span inside a fiber whose context was never initialised, as a root, without a warning', function () {
    [$tracer, $exporter] = fiberTracer();

    $ids = insideFiberWithWarningsFatal(static function () use ($tracer): array {
        $server = $tracer->startSpan('GET /orders', SpanKind::Server);
        $child = $tracer->startSpan('cqrs');
        $current = $tracer->currentSpan()?->spanId();
        $child->end();
        $server->end();

        return ['server' => $server->spanId(), 'child' => $child->spanId(), 'current' => $current];
    });

    /** @var list<ImmutableSpan> $spans */
    $spans = $exporter->getSpans();
    $byName = [];
    foreach ($spans as $span) {
        $byName[$span->getName()] = $span;
    }

    expect($ids['current'])->toBe($ids['child'])
        ->and($byName['GET /orders']->getParentSpanId())->toBe('0000000000000000')
        ->and($byName['cqrs']->getParentSpanId())->toBe($ids['server'])
        // The fiber's context is its own: the main fiber saw none of it.
        ->and($tracer->currentSpan())->toBeNull();
});

it('reads currentSpan() inside a fresh fiber as null, without a warning', function () {
    [$tracer] = fiberTracer();

    expect(insideFiberWithWarningsFatal(static fn (): ?string => $tracer->currentSpan()?->spanId()))->toBeNull();
});

it('does not shadow a scope the fiber already holds: the framework span nests under it', function () {
    [$tracer, $exporter] = fiberTracer();
    $provider = $tracer->provider();

    $ids = insideFiberWithWarningsFatal(static function () use ($tracer, $provider): array {
        // An application's own OTel span, made current in this fiber before the framework starts anything —
        // attached the way the API asks fiber-aware code to (from the root, never through a read of the
        // uninitialised fiber context, which would itself be the warning).
        $foreign = $provider->getTracer('app')->spanBuilder('app.work')->setParent(false)->startSpan();
        $scope = Context::getRoot()->withContextValue($foreign)->activate();

        $framework = $tracer->startSpan('inside');
        $framework->end();

        $scope->detach();
        $foreign->end();

        return ['foreign' => $foreign->getContext()->getSpanId(), 'framework' => $framework->spanId()];
    });

    /** @var list<ImmutableSpan> $spans */
    $spans = $exporter->getSpans();
    $byName = [];
    foreach ($spans as $span) {
        $byName[$span->getName()] = $span;
    }

    expect($byName['inside']->getParentSpanId())->toBe($ids['foreign'])
        ->and(OtelSpan::getCurrent()->getContext()->isValid())->toBeFalse()
        ->and(Context::getCurrent())->toBe(Context::getRoot());
});
