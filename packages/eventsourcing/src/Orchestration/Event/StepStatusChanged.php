<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Orchestration\Event;

use DateTimeImmutable;
use Firefly\Domain\DomainEvent;
use Firefly\EventSourcing\Event\SerializableEvent;
use Firefly\EventSourcing\Event\StoredEvent;

/** One step's status (a StepStatus value) was set. */
final readonly class StepStatusChanged extends DomainEvent implements SerializableEvent
{
    public function __construct(public string $stepId, public string $status, ?string $eventId = null, ?DateTimeImmutable $occurredAt = null)
    {
        parent::__construct($eventId, $occurredAt);
    }

    /** @return array{stepId: string, status: string} */
    public function toPayload(): array
    {
        return ['stepId' => $this->stepId, 'status' => $this->status];
    }

    public static function fromPayload(array $payload, StoredEvent $event): DomainEvent
    {
        $stepId = $payload['stepId'] ?? '';
        $status = $payload['status'] ?? '';

        return new self(is_scalar($stepId) ? (string) $stepId : '', is_string($status) ? $status : '', $event->eventId, $event->occurredAt);
    }
}
