<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Event;

use DateTimeImmutable;

/**
 * An event as a store hands it back. `aggregateVersion` is its place in its aggregate's stream (1, 2, 3…, the
 * optimistic-locking version). `globalPosition` is its place on the store's global stream. It is null until a
 * reader numbers it: positions are given in COMMIT order, so a projection keeping one number as its checkpoint
 * never skips an event that committed late. `eventVersion` is the payload's schema version; upcasters raise it
 * on read. `recordedAt` is when the store wrote it, `occurredAt` when the domain says it happened.
 */
final readonly class StoredEvent
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $eventId,
        public string $aggregateId,
        public string $aggregateType,
        public int $aggregateVersion,
        public ?int $globalPosition,
        public string $eventType,
        public int $eventVersion,
        public array $payload,
        public EventMetadata $metadata,
        public DateTimeImmutable $occurredAt,
        public DateTimeImmutable $recordedAt,
    ) {}

    /** @param array<string, mixed> $payload */
    public function upcasted(string $eventType, int $eventVersion, array $payload): self
    {
        return new self($this->eventId, $this->aggregateId, $this->aggregateType, $this->aggregateVersion, $this->globalPosition, $eventType, $eventVersion, $payload, $this->metadata, $this->occurredAt, $this->recordedAt);
    }

    public function withGlobalPosition(int $position): self
    {
        return new self($this->eventId, $this->aggregateId, $this->aggregateType, $this->aggregateVersion, $position, $this->eventType, $this->eventVersion, $this->payload, $this->metadata, $this->occurredAt, $this->recordedAt);
    }
}
