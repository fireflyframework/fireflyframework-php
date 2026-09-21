<?php

declare(strict_types=1);

namespace Firefly\Observability\Tracing;

/**
 * The shipped tracer: every span is a NoOpSpan, nothing is ever current, and trace() just runs the callback.
 * Bound by ObservabilityAutoConfiguration behind #[ConditionalOnMissingBean], so OpenTelemetryAutoConfiguration
 * (or an application's own Tracer bean) replaces it without any call site changing.
 */
final class NoOpTracer implements Tracer
{
    public function startSpan(string $name, SpanKind $kind = SpanKind::Internal, array $attributes = [], ?SpanContext $parent = null): Span
    {
        return new NoOpSpan;
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
        return $callback(new NoOpSpan);
    }
}
