<?php

declare(strict_types=1);

namespace Firefly\Testing\Double;

use Firefly\Observability\Tracing\Span;
use Firefly\Observability\Tracing\SpanContext;
use Firefly\Observability\Tracing\SpanKind;
use Firefly\Observability\Tracing\SpanStatus;
use Firefly\Observability\Tracing\Tracer;
use Throwable;

/**
 * The first-party in-memory tracer: the full Tracer port with nothing exported — spans are kept as RecordedSpan
 * rows, ids are minted with random_bytes in the W3C shape, and the active stack is a plain array. Bind it as the
 * Tracer in a test (or hand it to a filter directly) and assert on recorded(); the M13 `$spans` list of names is
 * kept so the original one-line assertion still holds.
 */
final class RecordingTracer implements Tracer
{
    /** @var list<string> every span name, in start order */
    public array $spans = [];

    /** @var list<RecordedSpan> */
    private array $recorded = [];

    /** @var list<RecordedSpan> the active stack, innermost last */
    private array $active = [];

    public function startSpan(string $name, SpanKind $kind = SpanKind::Internal, array $attributes = [], ?SpanContext $parent = null): Span
    {
        $parent ??= $this->currentSpan()?->context();
        $parent = $parent !== null && $parent->isValid() ? $parent : null;

        $context = $parent === null
            ? SpanContext::generate()
            : SpanContext::generate($parent->traceId, $parent->sampled, $parent->traceState);

        $span = new RecordedSpan($name, $kind, $context, $parent, $attributes, function (RecordedSpan $ended): void {
            $this->active = array_values(array_filter($this->active, static fn (RecordedSpan $span): bool => $span !== $ended));
        });

        $this->spans[] = $name;
        $this->recorded[] = $span;
        $this->active[] = $span;

        return $span;
    }

    public function currentSpan(): ?Span
    {
        return $this->active === [] ? null : $this->active[array_key_last($this->active)];
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
        $span = $this->startSpan($name, $kind, $attributes);

        try {
            return $callback($span);
        } catch (Throwable $e) {
            $span->recordException($e)->setStatus(SpanStatus::Error, $e->getMessage());

            throw $e;
        } finally {
            $span->end();
        }
    }

    /** @return list<RecordedSpan> */
    public function recorded(): array
    {
        return $this->recorded;
    }

    public function find(string $name): ?RecordedSpan
    {
        foreach ($this->recorded as $span) {
            if ($span->name === $name) {
                return $span;
            }
        }

        return null;
    }

    /** @return list<RecordedSpan> */
    public function ofKind(SpanKind $kind): array
    {
        return array_values(array_filter($this->recorded, static fn (RecordedSpan $span): bool => $span->kind === $kind));
    }

    public function reset(): void
    {
        $this->spans = [];
        $this->recorded = [];
        $this->active = [];
    }
}
