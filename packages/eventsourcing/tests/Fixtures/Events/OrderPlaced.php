<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Tests\Fixtures\Events;

use DateTimeImmutable;
use Firefly\Domain\DomainEvent;
use Firefly\EventSourcing\Event\Attributes\EventType;

/** A convention-mapped event: public promoted properties, the base's identity forwarded. */
#[EventType(name: 'order.placed', version: 2)]
final readonly class OrderPlaced extends DomainEvent
{
    /**
     * @param  list<string>  $skus
     */
    public function __construct(
        public string $orderId,
        public float $total,
        public OrderChannel $channel = OrderChannel::Web,
        public ?DateTimeImmutable $promisedBy = null,
        public array $skus = [],
        ?string $eventId = null,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        parent::__construct($eventId, $occurredAt);
    }
}
