<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Orchestration\Event;

use DateTimeImmutable;
use Firefly\Domain\DomainEvent;
use Firefly\EventSourcing\Event\SerializableEvent;
use Firefly\EventSourcing\Event\StoredEvent;

/** The execution was deleted (by delete() or the retention cleanup): a tombstone, since events are never purged. */
final readonly class ExecutionDeleted extends DomainEvent implements SerializableEvent
{
    public function __construct(?string $eventId = null, ?DateTimeImmutable $occurredAt = null)
    {
        parent::__construct($eventId, $occurredAt);
    }

    /** @return array{} */
    public function toPayload(): array
    {
        return [];
    }

    public static function fromPayload(array $payload, StoredEvent $event): DomainEvent
    {
        return new self($event->eventId, $event->occurredAt);
    }
}
