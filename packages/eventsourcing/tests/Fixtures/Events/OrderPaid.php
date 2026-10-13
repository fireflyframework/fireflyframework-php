<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Tests\Fixtures\Events;

use DateTimeImmutable;
use Firefly\Domain\DomainEvent;
use Firefly\EventSourcing\Event\Attributes\EventType;
use Firefly\EventSourcing\Event\SerializableEvent;
use Firefly\EventSourcing\Event\StoredEvent;

/** A hand-mapped event: its stored payload uses names of its own. */
#[EventType(name: 'order.paid')]
final readonly class OrderPaid extends DomainEvent implements SerializableEvent
{
    public function __construct(public string $orderId, public int $cents, ?string $eventId = null, ?DateTimeImmutable $occurredAt = null)
    {
        parent::__construct($eventId, $occurredAt);
    }

    /** @return array{order: string, amount: int} */
    public function toPayload(): array
    {
        return ['order' => $this->orderId, 'amount' => $this->cents];
    }

    public static function fromPayload(array $payload, StoredEvent $event): DomainEvent
    {
        $order = $payload['order'] ?? '';
        $amount = $payload['amount'] ?? 0;

        return new self(is_string($order) ? $order : '', is_int($amount) ? $amount : 0, $event->eventId, $event->occurredAt);
    }
}
