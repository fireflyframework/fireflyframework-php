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
 *
 * Being current and being open are two things. Tracer::startSpan() does both at once — right for work that
 * ends in the frame that started it, which is nearly everything — and end() releases both. deactivate() is the
 * seam for the rest: a span whose end is deferred to a promise's then() or a callback fired later stops being
 * current the moment its synchronous part is over, while staying open for the attributes, status and end()
 * that arrive with the result. Spring's SpanInScope::close() beside Span::end(), and OpenTelemetry's
 * Scope::detach() beside SpanInterface::end(), draw the same line.
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

    /**
     * Records the throwable as an `exception` event — exception.type, exception.message and exception.stacktrace
     * derived from it, the OpenTelemetry API's shape. `$attributes` are merged over those, and that is the seam
     * for an instrumentation that must not put the throwable's own message on the wire: an outbound HTTP
     * failure whose message ends in the request URI, query string and all, records itself with a redacted
     * `exception.message` while the throwable it rethrows stays untouched.
     *
     * @param  array<string, bool|int|float|string|array<mixed>|null>  $attributes
     */
    public function recordException(Throwable $exception, array $attributes = []): static;

    /**
     * Stops the span being the tracer's current span without ending it, so what starts next is a sibling
     * rather than a child; the span stays open. Idempotent, and a no-op once end() has run — end() releases the
     * activation itself, so a span that ends in the frame that started it never needs this.
     */
    public function deactivate(): void;

    public function end(): void;
}
