<?php

declare(strict_types=1);

namespace Firefly\Testing\Double;

use Closure;
use Firefly\Observability\Tracing\Span;
use Firefly\Observability\Tracing\SpanContext;
use Firefly\Observability\Tracing\SpanKind;
use Firefly\Observability\Tracing\SpanStatus;
use Throwable;

/**
 * A span RecordingTracer hands out: every mutation lands in a public field so a test reads it like a row. The
 * ids are real W3C-shaped values (see SpanContext::generate()), so propagation can be asserted byte for byte
 * without the OpenTelemetry SDK installed.
 */
final class RecordedSpan implements Span
{
    public string $name;

    /** @var array<string, bool|int|float|string|array<mixed>|null> */
    public array $attributes;

    /** @var list<array{name: string, attributes: array<string, bool|int|float|string|array<mixed>|null>}> */
    public array $events = [];

    public SpanStatus $status = SpanStatus::Unset;

    public string $statusDescription = '';

    public ?Throwable $exception = null;

    public bool $ended = false;

    private bool $active = true;

    /**
     * @param  array<string, bool|int|float|string|array<mixed>|null>  $attributes
     * @param  Closure(self): void  $onDeactivate  the tracer's "this span is no longer current" hook — fired
     *                                             once, by deactivate() or by the end() that comes first
     */
    public function __construct(
        string $name,
        public readonly SpanKind $kind,
        private readonly SpanContext $context,
        public readonly ?SpanContext $parent,
        array $attributes,
        private readonly Closure $onDeactivate,
    ) {
        $this->name = $name;
        $this->attributes = $attributes;
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
        return true;
    }

    public function updateName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function setAttribute(string $key, bool|int|float|string|array|null $value): static
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    public function setAttributes(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            $this->attributes[$key] = $value;
        }

        return $this;
    }

    public function addEvent(string $name, array $attributes = []): static
    {
        $this->events[] = ['name' => $name, 'attributes' => $attributes];

        return $this;
    }

    public function setStatus(SpanStatus $status, string $description = ''): static
    {
        $this->status = $status;
        $this->statusDescription = $description;

        return $this;
    }

    public function recordException(Throwable $exception): static
    {
        $this->exception = $exception;
        $this->events[] = ['name' => 'exception', 'attributes' => ['exception.type' => $exception::class, 'exception.message' => $exception->getMessage()]];

        return $this;
    }

    public function deactivate(): void
    {
        if (! $this->active) {
            return;
        }

        $this->active = false;
        ($this->onDeactivate)($this);
    }

    public function end(): void
    {
        if ($this->ended) {
            return;
        }

        $this->ended = true;
        $this->deactivate();
    }
}
