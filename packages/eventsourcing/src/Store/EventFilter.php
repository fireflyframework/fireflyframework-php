<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Store;

use DateTimeImmutable;
use Firefly\EventSourcing\Event\StoredEvent;

/**
 * Narrows a global read: any of the event types, any of the aggregate types, occurredAt in [from, before), and one
 * tenant. Empty lists and nulls do not narrow. The database store turns each member into a WHERE; matches() is the
 * same rule for the memory store. The filter matches the STORED type and metadata, before upcasting: a WHERE can
 * only see the stored columns, so an event whose upcaster renames it is found by its stored name, never by the
 * name it is read as.
 */
final readonly class EventFilter
{
    /**
     * @param  list<string>  $eventTypes
     * @param  list<string>  $aggregateTypes
     */
    public function __construct(
        public array $eventTypes = [],
        public array $aggregateTypes = [],
        public ?DateTimeImmutable $occurredFrom = null,
        public ?DateTimeImmutable $occurredBefore = null,
        public ?string $tenantId = null,
    ) {}

    public static function ofEventTypes(string ...$types): self
    {
        return new self(eventTypes: array_values($types));
    }

    public static function ofAggregateTypes(string ...$types): self
    {
        return new self(aggregateTypes: array_values($types));
    }

    public static function between(DateTimeImmutable $from, DateTimeImmutable $before): self
    {
        return new self(occurredFrom: $from, occurredBefore: $before);
    }

    public function matches(StoredEvent $event): bool
    {
        return ($this->eventTypes === [] || in_array($event->eventType, $this->eventTypes, true))
            && ($this->aggregateTypes === [] || in_array($event->aggregateType, $this->aggregateTypes, true))
            && ($this->occurredFrom === null || $event->occurredAt >= $this->occurredFrom)
            && ($this->occurredBefore === null || $event->occurredAt < $this->occurredBefore)
            && ($this->tenantId === null || $event->metadata->tenantId === $this->tenantId);
    }
}
