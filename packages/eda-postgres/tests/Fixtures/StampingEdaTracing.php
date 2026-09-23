<?php

declare(strict_types=1);

namespace Firefly\Eda\Postgres\Tests\Fixtures;

use Firefly\Eda\EventEnvelope;
use Firefly\Eda\Tracing\EdaTracing;

/**
 * A faithful, dependency-free stand-in for firefly/observability's TracerEdaTracing — enough of the W3C rules that
 * a test can tell a CONTINUED trace from a NEW one, which is the whole question on the relay hop.
 *
 * It models the three behaviours that matter and nothing else:
 *   - tracePublish() stamps a `traceparent` LAST, overwriting whatever the caller passed, under the trace currently
 *     in scope (a new root when nothing is in scope) — exactly what `[...$headers, ...$propagator->inject(...)]`
 *     does after a startSpan() with no explicit parent;
 *   - traceConsume() puts the ENVELOPE'S traceparent in scope for the duration of the delivery — the remote parent
 *     winning over anything current, which is the rule TracerEdaTracing documents;
 *   - a span in scope is restored on the way out, so nesting composes the way the tracer's active stack does.
 *
 * So a publish nested inside a consume inherits the consumed envelope's TRACE id and only mints a new SPAN id,
 * while an unnested publish mints both. traceId() is what an assertion compares.
 */
final class StampingEdaTracing implements EdaTracing
{
    /** @var list<array{destination: string, eventType: string, headers: array<string, string>}> every publish, in order */
    public array $published = [];

    /** @var list<EventEnvelope> every envelope handed to traceConsume(), in order */
    public array $consumed = [];

    /** The traceparent of the span currently in scope — the tracer's active stack, reduced to one frame. */
    private ?string $scope = null;

    private int $minted = 0;

    /** The 32-hex trace id half of a `00-<trace>-<span>-01` traceparent, or '' when there is none. */
    public static function traceId(?string $traceparent): string
    {
        $parts = explode('-', (string) $traceparent);

        return $parts[1] ?? '';
    }

    public static function traceparent(int $trace, int $span): string
    {
        return sprintf('00-%032x-%016x-01', $trace, $span);
    }

    /**
     * @param  array<string, string>  $headers
     * @param  callable(array<string, string>): void  $send
     */
    public function tracePublish(string $destination, string $eventType, array $headers, callable $send): void
    {
        $stamped = [...$headers, 'traceparent' => $this->startSpan()];
        $this->published[] = ['destination' => $destination, 'eventType' => $eventType, 'headers' => $stamped];

        $this->inScope($stamped['traceparent'], static fn () => $send($stamped));
    }

    /** @param callable(EventEnvelope): void $deliver */
    public function traceConsume(EventEnvelope $envelope, callable $deliver): void
    {
        $this->consumed[] = $envelope;

        // The envelope's traceparent is the REMOTE parent and wins over anything current; without one this is a
        // root consumer span, which is what a worker sees for an untraced message.
        $this->inScope($envelope->headers['traceparent'] ?? $this->startSpan(), static fn () => $deliver($envelope));
    }

    /** A span under the trace in scope, or a brand-new root trace when nothing is in scope. */
    private function startSpan(): string
    {
        $this->minted++;

        return $this->scope === null
            ? self::traceparent($this->minted, $this->minted)
            : sprintf('00-%s-%016x-01', self::traceId($this->scope), $this->minted);
    }

    /** @param callable(): void $work */
    private function inScope(string $traceparent, callable $work): void
    {
        $previous = $this->scope;
        $this->scope = $traceparent;

        try {
            $work();
        } finally {
            $this->scope = $previous;
        }
    }
}
