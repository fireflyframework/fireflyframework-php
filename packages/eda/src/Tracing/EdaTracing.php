<?php

declare(strict_types=1);

namespace Firefly\Eda\Tracing;

use Firefly\Eda\EventEnvelope;

/**
 * The tracing SEAM around an envelope's two boundary crossings — CqrsTracing's sibling for the broker bus.
 *
 * tracePublish() wraps the hand-over to the transport and, because propagation means WRITING something on the
 * envelope, it owns the headers: $send receives the headers to put on the envelope (the caller's plus whatever
 * the implementation adds — a traceparent), then the adapter builds and sends. traceConsume() wraps the
 * delivery of one received envelope to the subscribers. Called by InMemoryEventBus (publish + the synchronous
 * delivery, nested), QueueEventBus (publish; deliver() on the worker) and SubscriberRegistrySink (the sink
 * every broker consumer feeds), so a CONSUMER span exists whichever transport carried the envelope, while the
 * PRODUCER span and the traceparent it stamps exist for the in-memory and queue buses only: eda-rabbitmq,
 * eda-kafka and eda-postgres build their envelopes themselves and do not reach tracePublish() yet, which is
 * the follow-up docs/modules/tracing.md lists under "Known-latent". NoOpEdaTracing is the shipped default; the
 * real one (spans over the Tracer port) lives in firefly/observability and wins by bean precedence. Nothing in
 * firefly/eda depends on observability.
 */
interface EdaTracing
{
    /**
     * @param  array<string, string>  $headers
     * @param  callable(array<string, string>): void  $send
     */
    public function tracePublish(string $destination, string $eventType, array $headers, callable $send): void;

    /** @param callable(EventEnvelope): void $deliver */
    public function traceConsume(EventEnvelope $envelope, callable $deliver): void;
}
