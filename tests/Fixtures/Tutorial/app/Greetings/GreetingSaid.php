<?php

declare(strict_types=1);

namespace App\Greetings;

use DateTimeImmutable;
use Firefly\Domain\DomainEvent;
use Firefly\EventSourcing\Event\Attributes\EventType;

#[EventType(name: 'greeting.said')]
final readonly class GreetingSaid extends DomainEvent
{
    public function __construct(
        public string $name,
        public string $message,
        ?string $eventId = null,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        parent::__construct($eventId, $occurredAt);
    }
}
