<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Orchestration\Event;

use DateTimeImmutable;
use Firefly\Domain\DomainEvent;
use Firefly\EventSourcing\Event\SerializableEvent;
use Firefly\EventSourcing\Event\StoredEvent;

/** One step's result was recorded; `result` is `StateSerializer::encodeArray(['value' => <result>])`. */
final readonly class StepResultRecorded extends DomainEvent implements SerializableEvent
{
    public function __construct(public string $stepId, public string $result, ?string $eventId = null, ?DateTimeImmutable $occurredAt = null)
    {
        parent::__construct($eventId, $occurredAt);
    }

    /** @return array{stepId: string, result: string} */
    public function toPayload(): array
    {
        return ['stepId' => $this->stepId, 'result' => $this->result];
    }

    public static function fromPayload(array $payload, StoredEvent $event): DomainEvent
    {
        $stepId = $payload['stepId'] ?? '';
        $result = $payload['result'] ?? '';

        return new self(is_scalar($stepId) ? (string) $stepId : '', is_string($result) ? $result : '', $event->eventId, $event->occurredAt);
    }
}
