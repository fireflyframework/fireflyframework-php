<?php

declare(strict_types=1);

namespace Firefly\Eda\Bus;

use Firefly\Eda\EventEnvelope;
use Firefly\Eda\EventPublisher;

/**
 * The default EventPublisher adapter: an in-process synchronous bus over a SubscriberRegistry. publish() builds an
 * EventEnvelope and delivers it immediately to every matching subscriber; start()/stop() are no-ops (nothing to
 * connect). Zero external services — the skeleton default. Handlers arrive already retry/DLQ-wrapped from the
 * wiring pass, so this bus applies no policy of its own.
 */
final class InMemoryEventBus implements EventPublisher
{
    public function __construct(private readonly SubscriberRegistry $registry) {}

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
        $this->registry->deliver(new EventEnvelope($eventType, $destination, $payload, $headers));
    }

    public function start(): void {}

    public function stop(): void {}
}
