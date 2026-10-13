<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Orchestration\Event;

use DateTimeImmutable;
use Firefly\Domain\DomainEvent;
use Firefly\EventSourcing\Event\SerializableEvent;
use Firefly\EventSourcing\Event\StoredEvent;

/** The execution's status and its clock fields, in the ExecutionState::toArray() form (UTC ISO-8601 instants). */
final readonly class ExecutionStatusChanged extends DomainEvent implements SerializableEvent
{
    public function __construct(
        public string $status,
        public string $updatedAt,
        public ?string $completedAt,
        public ?string $wakeAt,
        ?string $eventId = null,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        parent::__construct($eventId, $occurredAt);
    }

    /** @return array{status: string, updatedAt: string, completedAt: ?string, wakeAt: ?string} */
    public function toPayload(): array
    {
        return ['status' => $this->status, 'updatedAt' => $this->updatedAt, 'completedAt' => $this->completedAt, 'wakeAt' => $this->wakeAt];
    }

    public static function fromPayload(array $payload, StoredEvent $event): DomainEvent
    {
        $status = $payload['status'] ?? '';
        $updatedAt = $payload['updatedAt'] ?? '';
        $completedAt = $payload['completedAt'] ?? null;
        $wakeAt = $payload['wakeAt'] ?? null;

        return new self(
            is_string($status) ? $status : '',
            is_string($updatedAt) ? $updatedAt : '',
            is_string($completedAt) ? $completedAt : null,
            is_string($wakeAt) ? $wakeAt : null,
            $event->eventId,
            $event->occurredAt,
        );
    }
}
