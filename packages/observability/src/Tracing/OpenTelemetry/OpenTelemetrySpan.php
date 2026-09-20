<?php

declare(strict_types=1);

namespace Firefly\Observability\Tracing\OpenTelemetry;

use Firefly\Observability\Tracing\Span;
use Firefly\Observability\Tracing\SpanContext;
use Firefly\Observability\Tracing\SpanStatus;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\ScopeInterface;
use Throwable;

/**
 * The port's Span over an OpenTelemetry span. The activation scope — what makes the span "current" for the
 * SDK's own Context — is detached on end(), which is why end() is idempotent here: a span ended twice must not
 * detach twice. A span obtained from OpenTelemetryTracer::currentSpan() carries no scope, because the scope
 * belongs to whoever started the span.
 */
final class OpenTelemetrySpan implements Span
{
    private readonly SpanContext $context;

    private bool $ended = false;

    public function __construct(
        private readonly SpanInterface $span,
        private ?ScopeInterface $scope,
    ) {
        $otel = $span->getContext();
        $this->context = new SpanContext(
            $otel->getTraceId(),
            $otel->getSpanId(),
            $otel->isSampled(),
            (string) ($otel->getTraceState() ?? ''),
            $otel->isRemote(),
        );
    }

    /** Makes the span the SDK's current one until end() — what startSpan() does right after building it. */
    public function activated(): self
    {
        $this->scope ??= $this->span->activate();

        return $this;
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
        return $this->span->isRecording();
    }

    public function updateName(string $name): static
    {
        // The OTel API wants non-empty strings for names and attribute keys; an empty one is a caller bug the
        // port tolerates (a no-op) rather than a TypeError in the middle of a request.
        if ($name !== '') {
            $this->span->updateName($name);
        }

        return $this;
    }

    public function setAttribute(string $key, bool|int|float|string|array|null $value): static
    {
        if ($key !== '') {
            $this->span->setAttribute($key, $value);
        }

        return $this;
    }

    public function setAttributes(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute($key, $value);
        }

        return $this;
    }

    public function addEvent(string $name, array $attributes = []): static
    {
        $this->span->addEvent($name, $attributes);

        return $this;
    }

    public function setStatus(SpanStatus $status, string $description = ''): static
    {
        $this->span->setStatus(match ($status) {
            SpanStatus::Ok => StatusCode::STATUS_OK,
            SpanStatus::Error => StatusCode::STATUS_ERROR,
            SpanStatus::Unset => StatusCode::STATUS_UNSET,
        }, $description === '' ? null : $description);

        return $this;
    }

    public function recordException(Throwable $exception): static
    {
        $this->span->recordException($exception);

        return $this;
    }

    public function end(): void
    {
        if ($this->ended) {
            return;
        }

        $this->ended = true;
        $this->span->end();
        $this->scope?->detach();
    }
}
