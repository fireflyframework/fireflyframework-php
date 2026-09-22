<?php

declare(strict_types=1);

namespace Firefly\Observability\Tracing;

/**
 * The immutable identity of a span, in the W3C Trace Context shape every propagation format and backend
 * speaks: a 32-hex-character trace id, a 16-hex-character span id, the sampled flag, and the opaque
 * `tracestate` list. It is a value object so that the port (Tracer/Span) never leaks an SDK type: the
 * OpenTelemetry adapter converts to and from its own SpanContext at its boundary, the propagator formats
 * and parses this one, and a test's RecordingTracer mints them with random_bytes.
 *
 * `remote` marks a context that was EXTRACTED from a carrier (an inbound request header, an envelope) rather
 * than started here — the distinction a sampler and a backend both care about.
 */
final readonly class SpanContext
{
    public const string INVALID_TRACE_ID = '00000000000000000000000000000000';

    public const string INVALID_SPAN_ID = '0000000000000000';

    public function __construct(
        public string $traceId,
        public string $spanId,
        public bool $sampled = true,
        public string $traceState = '',
        public bool $remote = false,
    ) {}

    /** The context of a span that records nothing: what NoOpTracer hands out, and what "start a new root" means as a parent. */
    public static function invalid(): self
    {
        return new self(self::INVALID_TRACE_ID, self::INVALID_SPAN_ID, false);
    }

    /** A fresh context: a new span id, and either the given trace id (a child) or a new one (a root). */
    public static function generate(?string $traceId = null, bool $sampled = true, string $traceState = ''): self
    {
        return new self($traceId ?? bin2hex(random_bytes(16)), bin2hex(random_bytes(8)), $sampled, $traceState);
    }

    /**
     * Lowercase hex of the right length, and not the all-zero value the W3C spec reserves for "no id" — the
     * same rule the spec gives a receiver for deciding whether to continue an inbound traceparent.
     */
    public function isValid(): bool
    {
        return preg_match('/^[0-9a-f]{32}$/', $this->traceId) === 1
            && $this->traceId !== self::INVALID_TRACE_ID
            && preg_match('/^[0-9a-f]{16}$/', $this->spanId) === 1
            && $this->spanId !== self::INVALID_SPAN_ID;
    }

    /** The two-hex-digit `trace-flags` field of a traceparent: only the sampled bit is defined today. */
    public function traceFlags(): string
    {
        return $this->sampled ? '01' : '00';
    }
}
