<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Orchestration\Event;

use DateTimeImmutable;
use Firefly\Domain\DomainEvent;
use Firefly\EventSourcing\Event\SerializableEvent;
use Firefly\EventSourcing\Event\StoredEvent;

/** The execution's search attributes were replaced; `attributes` is `StateSerializer::encodeArray(['value' => <map>])`. */
final readonly class SearchAttributesChanged extends DomainEvent implements SerializableEvent
{
    public function __construct(public string $attributes, ?string $eventId = null, ?DateTimeImmutable $occurredAt = null)
    {
        parent::__construct($eventId, $occurredAt);
    }

    /** @return array{attributes: string} */
    public function toPayload(): array
    {
        return ['attributes' => $this->attributes];
    }

    public static function fromPayload(array $payload, StoredEvent $event): DomainEvent
    {
        $attributes = $payload['attributes'] ?? '';

        return new self(is_string($attributes) ? $attributes : '', $event->eventId, $event->occurredAt);
    }
}
