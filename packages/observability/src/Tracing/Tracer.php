<?php

declare(strict_types=1);

namespace Firefly\Observability\Tracing;

/**
 * The tracing port — the Spring `Tracer` / OpenTelemetry `TracerInterface` shape, reduced to what LaraFly's own
 * instrumentation needs and what an application's code should ever have to call.
 *
 * startSpan() starts a span AND makes it the current one until end() — or until Span::deactivate(), for a span
 * whose end is deferred past the frame that started it: a span started with no explicit parent is a child of
 * the current span; an invalid parent (SpanContext::invalid()) starts a new root; a valid remote parent (from
 * W3CTraceContextPropagator::extract()) continues that trace. currentSpan() is what a log processor reads.
 * trace() is the M12 convenience kept intact: start, run, record a throwable as an ERROR status plus an
 * exception event, rethrow, end — the callback now receives the span, and a zero-argument callback still
 * works.
 *
 * NoOpTracer is the shipped default; OpenTelemetryTracer drops in when the SDK is installed and
 * `firefly.observability.tracing.enabled` is on; firefly/testing's RecordingTracer is the in-memory one.
 */
interface Tracer
{
    /** @param array<string, bool|int|float|string|array<mixed>|null> $attributes */
    public function startSpan(string $name, SpanKind $kind = SpanKind::Internal, array $attributes = [], ?SpanContext $parent = null): Span;

    public function currentSpan(): ?Span;

    /**
     * @template T
     *
     * @param  callable(Span): T  $callback
     * @param  array<string, bool|int|float|string|array<mixed>|null>  $attributes
     * @return T
     */
    public function trace(string $name, callable $callback, SpanKind $kind = SpanKind::Internal, array $attributes = []): mixed;
}
