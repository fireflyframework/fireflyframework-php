<?php

declare(strict_types=1);

namespace Firefly\Eda\Bus;

use Firefly\Eda\EventEnvelope;
use Firefly\Eda\EventPublisher;
use Firefly\Eda\Tracing\EdaTracing;
use Firefly\Eda\Tracing\NoOpEdaTracing;

/**
 * The default EventPublisher adapter: an in-process synchronous bus over a SubscriberRegistry. publish() builds an
 * EventEnvelope and delivers it immediately to every matching subscriber; start()/stop() are no-ops (nothing to
 * connect). Zero external services — the skeleton default. Handlers arrive already retry/DLQ-wrapped from the
 * wiring pass, so this bus applies no policy of its own. Both boundary crossings run through the EdaTracing seam
 * (NoOp by default).
 */
final class InMemoryEventBus implements EventPublisher
{
    private readonly EdaTracing $tracing;

    public function __construct(private readonly SubscriberRegistry $registry, ?EdaTracing $tracing = null)
    {
        $this->tracing = $tracing ?? new NoOpEdaTracing;
    }

    public function subscribe(string $eventTypePattern, callable $handler): void
    {
        $this->registry->subscribe($eventTypePattern, $handler);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    public function publish(string $destination, string $eventType, array $payload, array $headers = []): void
    {
        // Delivery is synchronous, so the consume side is NESTED in the publish side: the PRODUCER span
        // wraps the CONSUMER span, and the envelope carries the traceparent between them exactly as it
        // would across a broker.
        $this->tracing->tracePublish($destination, $eventType, $headers, function (array $headers) use ($destination, $eventType, $payload): void {
            $envelope = new EventEnvelope($eventType, $destination, $payload, $headers);

            $this->tracing->traceConsume($envelope, fn (EventEnvelope $received) => $this->registry->deliver($received));
        });
    }

    public function start(): void {}

    public function stop(): void {}
}
