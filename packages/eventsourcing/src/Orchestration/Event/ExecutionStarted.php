<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Orchestration\Event;

use DateTimeImmutable;
use Firefly\Domain\DomainEvent;
use Firefly\EventSourcing\Event\SerializableEvent;
use Firefly\EventSourcing\Event\StoredEvent;

/**
 * An execution's first checkpoint: the whole ExecutionState::toArray(), as StateSerializer JSON. The state travels as
 * a string so it survives the event envelope byte for byte (a whole float stays 10.0).
 */
final readonly class ExecutionStarted extends DomainEvent implements SerializableEvent
{
    public function __construct(public string $state, ?string $eventId = null, ?DateTimeImmutable $occurredAt = null)
    {
        parent::__construct($eventId, $occurredAt);
    }

    /** @return array{state: string} */
    public function toPayload(): array
    {
        return ['state' => $this->state];
    }

    public static function fromPayload(array $payload, StoredEvent $event): DomainEvent
    {
        $state = $payload['state'] ?? '';

        return new self(is_string($state) ? $state : '', $event->eventId, $event->occurredAt);
    }
}
