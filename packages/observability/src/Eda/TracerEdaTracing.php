<?php

declare(strict_types=1);

namespace Firefly\Observability\Eda;

use Firefly\Eda\EventEnvelope;
use Firefly\Eda\Tracing\EdaTracing;
use Firefly\Observability\Tracing\Span;
use Firefly\Observability\Tracing\SpanKind;
use Firefly\Observability\Tracing\SpanStatus;
use Firefly\Observability\Tracing\Tracer;
use Firefly\Observability\Tracing\W3CTraceContextPropagator;
use Throwable;

/**
 * The real EdaTracing — a PRODUCER span around every publish and a CONSUMER span around every delivery, joined
 * by the traceparent this class writes into the envelope headers on the way out and reads back on the way in.
 * Span names and attributes follow the OTel messaging semantic conventions (`{operation} {destination}`,
 * messaging.system / messaging.destination.name / messaging.operation.type / messaging.message.id); the
 * system name is `firefly-eda` because the envelope is the protocol here, whatever broker carries it.
 *
 * On consume the envelope's traceparent wins over any span that happens to be current: in-process (the
 * in-memory bus) the two agree; on a queue worker or a broker consumer there is no current span, and the
 * remote parent is the only link back to the request that published. An envelope with no (or a malformed)
 * traceparent — one published before tracing was switched on, or by a foreign producer — falls back to the
 * port's default parent: the current span when one exists (a replay triggered from inside a request stays
 * under that request), and a new root only when nothing is current, which is what a worker or a broker
 * consumer sees. That is the same rule TracingFilter applies to an inbound request without a traceparent,
 * and what OpenTelemetry's own extract() does when the carrier is empty.
 *
 * Unlike TracerCqrsTracing this does not go through Tracer::trace(): the PRODUCER span's context must be
 * injected into the headers BEFORE the transport runs, and the CONSUMER span's parent comes from the envelope
 * rather than from the current span, so both sides start their span by hand and share the one run() that
 * records a throwable as an ERROR status plus an exception event, rethrows, and always ends the span.
 */
final class TracerEdaTracing implements EdaTracing
{
    public const string SYSTEM = 'firefly-eda';

    public function __construct(
        private readonly Tracer $tracer,
        private readonly W3CTraceContextPropagator $propagator,
    ) {}

    /**
     * @param  array<string, string>  $headers
     * @param  callable(array<string, string>): void  $send
     */
    public function tracePublish(string $destination, string $eventType, array $headers, callable $send): void
    {
        $span = $this->tracer->startSpan('publish '.$destination, SpanKind::Producer, [
            'messaging.system' => self::SYSTEM,
            'messaging.operation.type' => 'publish',
            'messaging.destination.name' => $destination,
            'firefly.eda.event_type' => $eventType,
        ]);

        $this->run($span, function () use ($send, $headers, $span): void {
            $send([...$headers, ...$this->propagator->inject($span->context())]);
        });
    }

    /** @param callable(EventEnvelope): void $deliver */
    public function traceConsume(EventEnvelope $envelope, callable $deliver): void
    {
        $span = $this->tracer->startSpan('process '.$envelope->destination, SpanKind::Consumer, [
            'messaging.system' => self::SYSTEM,
            'messaging.operation.type' => 'process',
            'messaging.destination.name' => $envelope->destination,
            'messaging.message.id' => $envelope->eventId,
            'firefly.eda.event_type' => $envelope->eventType,
        ], $this->propagator->extract($envelope->headers));

        $this->run($span, static function () use ($deliver, $envelope): void {
            $deliver($envelope);
        });
    }

    /** @param callable(): void $work */
    private function run(Span $span, callable $work): void
    {
        try {
            $work();
        } catch (Throwable $e) {
            $span->recordException($e)->setStatus(SpanStatus::Error, $e->getMessage());

            throw $e;
        } finally {
            $span->end();
        }
    }
}
