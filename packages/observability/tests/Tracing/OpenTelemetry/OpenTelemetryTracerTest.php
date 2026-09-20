<?php

declare(strict_types=1);

use Firefly\Observability\Tracing\OpenTelemetry\OpenTelemetryTracer;
use Firefly\Observability\Tracing\Span;
use Firefly\Observability\Tracing\SpanContext;
use Firefly\Observability\Tracing\SpanKind;
use Firefly\Observability\Tracing\SpanStatus;
use OpenTelemetry\API\Trace\SpanKind as OtelSpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Trace\EventInterface;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;

/** @return array{0: OpenTelemetryTracer, 1: InMemoryExporter} */
function otelTracer(): array
{
    $exporter = new InMemoryExporter;

    return [new OpenTelemetryTracer(new TracerProvider([new SimpleSpanProcessor($exporter)])), $exporter];
}

/** @return list<ImmutableSpan> */
function exportedSpans(InMemoryExporter $exporter): array
{
    /** @var list<ImmutableSpan> $spans */
    $spans = $exporter->getSpans();

    return $spans;
}

it('starts a recording span, makes it current, and exports it with its kind and attributes on end', function () {
    [$tracer, $exporter] = otelTracer();

    $span = $tracer->startSpan('GET', SpanKind::Server, ['http.request.method' => 'GET']);

    expect($span->isRecording())->toBeTrue()
        ->and($span->context()->isValid())->toBeTrue()
        ->and($tracer->currentSpan()?->spanId())->toBe($span->spanId());

    $span->updateName('GET /demo/{id}')->setAttribute('http.response.status_code', 200)->end();

    $exported = exportedSpans($exporter);
    expect($exported)->toHaveCount(1)
        ->and($exported[0]->getName())->toBe('GET /demo/{id}')
        ->and($exported[0]->getKind())->toBe(OtelSpanKind::KIND_SERVER)
        ->and($exported[0]->getAttributes()->toArray())->toBe(['http.request.method' => 'GET', 'http.response.status_code' => 200])
        ->and($exported[0]->getTraceId())->toBe($span->traceId())
        ->and($tracer->currentSpan())->toBeNull();
});

it('continues a remote parent: same trace id, the inbound span as parent, tracestate carried', function () {
    [$tracer, $exporter] = otelTracer();
    $remote = new SpanContext('4bf92f3577b34da6a3ce929d0e0e4736', '00f067aa0ba902b7', true, 'congo=t61rcWkgMzE', remote: true);

    $span = $tracer->startSpan('GET', SpanKind::Server, [], $remote);
    $span->end();

    $exported = exportedSpans($exporter)[0];
    expect($exported->getTraceId())->toBe('4bf92f3577b34da6a3ce929d0e0e4736')
        ->and($exported->getParentSpanId())->toBe('00f067aa0ba902b7')
        ->and($span->context()->traceState)->toBe('congo=t61rcWkgMzE');
});

it('nests under the current span by default and starts a root for an explicitly invalid parent', function () {
    [$tracer, $exporter] = otelTracer();

    $parent = $tracer->startSpan('parent');
    $child = $tracer->startSpan('child');
    $child->end();
    $root = $tracer->startSpan('root', SpanKind::Internal, [], SpanContext::invalid());
    $root->end();
    $parent->end();

    $byName = [];
    foreach (exportedSpans($exporter) as $span) {
        $byName[$span->getName()] = $span;
    }

    expect($byName['child']->getParentSpanId())->toBe($parent->spanId())
        ->and($byName['child']->getTraceId())->toBe($parent->traceId())
        // A root's parent is the all-zero (invalid) span context, not an empty string, in the SDK's ImmutableSpan.
        ->and($byName['root']->getParentContext()->isValid())->toBeFalse()
        ->and($byName['root']->getTraceId())->not->toBe($parent->traceId());
});

it('deactivate() releases the activation without ending the span, so what starts next is a sibling, not a child', function () {
    [$tracer, $exporter] = otelTracer();

    $parent = $tracer->startSpan('parent');
    $first = $tracer->startSpan('first');
    $first->deactivate();
    expect($tracer->currentSpan()?->spanId())->toBe($parent->spanId());

    $second = $tracer->startSpan('second');
    $second->end();
    // The deactivated span is still open: a late attribute lands, and end() exports it without a second detach.
    $first->setAttribute('late', true)->end();
    $first->deactivate();
    $parent->end();

    $byName = [];
    foreach (exportedSpans($exporter) as $span) {
        $byName[$span->getName()] = $span;
    }

    expect(array_keys($byName))->toBe(['second', 'first', 'parent'])
        ->and($byName['second']->getParentSpanId())->toBe($parent->spanId())
        ->and($byName['first']->getParentSpanId())->toBe($parent->spanId())
        ->and($byName['first']->getAttributes()->get('late'))->toBeTrue()
        ->and($tracer->currentSpan())->toBeNull();
});

it('maps status, events and a recorded exception onto the OTel span', function () {
    [$tracer, $exporter] = otelTracer();

    $span = $tracer->startSpan('work');
    $span->addEvent('retry', ['attempt' => 2])->recordException(new RuntimeException('boom'))->setStatus(SpanStatus::Error, 'boom');
    $span->end();
    $span->end(); // idempotent: no "span already ended" warning, no second export

    $exported = exportedSpans($exporter);
    expect($exported)->toHaveCount(1)
        ->and($exported[0]->getStatus()->getCode())->toBe(StatusCode::STATUS_ERROR)
        ->and($exported[0]->getStatus()->getDescription())->toBe('boom')
        ->and(array_map(static fn (EventInterface $event): string => $event->getName(), $exported[0]->getEvents()))->toBe(['retry', 'exception']);
});

it('trace() hands the span to the callback, ends it, and records a throwable as ERROR before rethrowing', function () {
    [$tracer, $exporter] = otelTracer();

    $result = $tracer->trace('ok', fn (Span $span): string => $span->traceId(), SpanKind::Client);
    expect($result)->toMatch('/^[0-9a-f]{32}$/');

    expect(fn () => $tracer->trace('failing', function (): never {
        throw new RuntimeException('nope');
    }))->toThrow(RuntimeException::class);

    $exported = exportedSpans($exporter);
    expect($exported)->toHaveCount(2)
        ->and($exported[0]->getKind())->toBe(OtelSpanKind::KIND_CLIENT)
        ->and($exported[1]->getName())->toBe('failing')
        ->and($exported[1]->getStatus()->getCode())->toBe(StatusCode::STATUS_ERROR)
        ->and($tracer->currentSpan())->toBeNull();
});
