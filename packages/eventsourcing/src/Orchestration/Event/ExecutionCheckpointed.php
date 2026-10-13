<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Orchestration\Event;

use DateTimeImmutable;
use Firefly\Domain\DomainEvent;
use Firefly\EventSourcing\Event\SerializableEvent;
use Firefly\EventSourcing\Event\StoredEvent;

/**
 * The lossless fallback: every top-level ExecutionState field the semantic events did not reproduce exactly, with its
 * new value, as `StateSerializer::encodeArray(<field => value>)`.
 */
final readonly class ExecutionCheckpointed extends DomainEvent implements SerializableEvent
{
    public function __construct(public string $changes, ?string $eventId = null, ?DateTimeImmutable $occurredAt = null)
    {
        parent::__construct($eventId, $occurredAt);
    }

    /** @return array{changes: string} */
    public function toPayload(): array
    {
        return ['changes' => $this->changes];
    }

    public static function fromPayload(array $payload, StoredEvent $event): DomainEvent
    {
        $changes = $payload['changes'] ?? '';

        return new self(is_string($changes) ? $changes : '', $event->eventId, $event->occurredAt);
    }
}
