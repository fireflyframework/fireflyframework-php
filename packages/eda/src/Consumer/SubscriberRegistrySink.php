<?php

declare(strict_types=1);

namespace Firefly\Eda\Consumer;

use Firefly\Eda\Bus\SubscriberRegistry;
use Firefly\Eda\EventEnvelope;
use Firefly\Eda\Tracing\EdaTracing;
use Firefly\Eda\Tracing\NoOpEdaTracing;

/**
 * The default EnvelopeSink: the #[EventListener] beans, via the SubscriberRegistry EventListenerWiringPass
 * populated at boot (its handlers already retry/DLQ-wrapped). This is exactly the callable `firefly:eda:consume`
 * used to build inline; it is a class so an application's own EnvelopeSink can be bound in its place. Delivery
 * runs through the EdaTracing seam (NoOp by default), which is how every broker's consume path — RabbitMQ,
 * Kafka, Postgres alike — gets its CONSUMER span without any broker package knowing about tracing.
 */
final class SubscriberRegistrySink implements EnvelopeSink
{
    private readonly EdaTracing $tracing;

    public function __construct(private readonly SubscriberRegistry $registry, ?EdaTracing $tracing = null)
    {
        $this->tracing = $tracing ?? new NoOpEdaTracing;
    }

    public function handle(EventEnvelope $envelope): void
    {
        $this->tracing->traceConsume($envelope, fn (EventEnvelope $received) => $this->registry->deliver($received));
    }
}
