<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Event;

use Firefly\Eda\EventEnvelope;
use Firefly\Eda\JsonSerializer;
use Firefly\Eda\Serializer;

/**
 * The stored form of an event IS an eda EventEnvelope: the event type, the aggregate type as its destination, the
 * payload, the metadata as headers, the event id and the instant. It is written by the BOUND eda Serializer
 * (firefly.eda.serialization_format), so a store and a broker speak one format. JSON gives a whole float back as
 * an int (`10.0` reads as `10`), exactly as on the eda wire. EventMapper coerces by the constructor's declared type,
 * and a value that must survive byte for byte travels as a string.
 */
final class EventEnvelopeCodec
{
    public function __construct(private readonly Serializer $serializer = new JsonSerializer) {}

    public function encode(NewEvent $event, string $aggregateType): string
    {
        return $this->serializer->serialize(new EventEnvelope($event->eventType, $aggregateType, $event->payload, $event->metadata->toHeaders(), $event->eventId, $event->occurredAt));
    }

    /** @return array{payload: array<string, mixed>, metadata: EventMetadata} */
    public function decode(string $raw): array
    {
        $envelope = $this->serializer->deserialize($raw);

        return ['payload' => $envelope->payload, 'metadata' => EventMetadata::fromHeaders($envelope->headers)];
    }
}
