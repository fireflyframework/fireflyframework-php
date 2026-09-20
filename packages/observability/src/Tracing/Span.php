<?php

declare(strict_types=1);

namespace Firefly\Observability\Tracing;

use Throwable;

/**
 * One unit of work in a trace, shaped after OpenTelemetry's SpanInterface and Spring's Span: a name that may be
 * refined once more is known (a SERVER span is started before the router has matched a route), attributes,
 * timestamped events, a three-valued status, a recorded exception, and an end. Every mutator returns the span so
 * instrumentation reads as one chain, and end() is idempotent so a `finally` can call it unconditionally.
 *
 * The two ids are exposed directly because that is what a log processor and an exchange row want; context()
 * carries the rest (sampled flag, tracestate) for propagation.
 */
interface Span
{
    public function context(): SpanContext;

    public function traceId(): string;

    public function spanId(): string;

    /** False for a span nobody will ever see (NoOp, or sampled out) — the cheap test before doing work for it. */
    public function isRecording(): bool;

    public function updateName(string $name): static;

    /** @param bool|int|float|string|array<mixed>|null $value */
    public function setAttribute(string $key, bool|int|float|string|array|null $value): static;

    /** @param array<string, bool|int|float|string|array<mixed>|null> $attributes */
    public function setAttributes(array $attributes): static;

    /** @param array<string, bool|int|float|string|array<mixed>|null> $attributes */
    public function addEvent(string $name, array $attributes = []): static;

    public function setStatus(SpanStatus $status, string $description = ''): static;

    public function recordException(Throwable $exception): static;

    public function end(): void;
}
