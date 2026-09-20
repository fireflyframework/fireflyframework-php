<?php

declare(strict_types=1);

namespace Firefly\Observability\Tracing;

use Throwable;

/**
 * The span NoOpTracer hands out: records nothing, carries an invalid context (so nothing publishes its ids or
 * writes a traceparent for it), and every mutator is a fluent no-op. Instrumentation is written once against
 * Span and costs a few method calls when tracing is off.
 */
final class NoOpSpan implements Span
{
    private readonly SpanContext $context;

    public function __construct(?SpanContext $context = null)
    {
        $this->context = $context ?? SpanContext::invalid();
    }

    public function context(): SpanContext
    {
        return $this->context;
    }

    public function traceId(): string
    {
        return $this->context->traceId;
    }

    public function spanId(): string
    {
        return $this->context->spanId;
    }

    public function isRecording(): bool
    {
        return false;
    }

    public function updateName(string $name): static
    {
        return $this;
    }

    public function setAttribute(string $key, bool|int|float|string|array|null $value): static
    {
        return $this;
    }

    public function setAttributes(array $attributes): static
    {
        return $this;
    }

    public function addEvent(string $name, array $attributes = []): static
    {
        return $this;
    }

    public function setStatus(SpanStatus $status, string $description = ''): static
    {
        return $this;
    }

    public function recordException(Throwable $exception, array $attributes = []): static
    {
        return $this;
    }

    public function deactivate(): void {}

    public function end(): void {}
}
