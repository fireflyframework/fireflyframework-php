<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Tests\Fixtures\Moods;

use DateTimeImmutable;
use Firefly\Domain\DomainEvent;
use Firefly\EventSourcing\Event\Attributes\EventType;

/** A convention-mapped event with a pure-enum property, read back by case name. */
#[EventType(name: 'mood.changed')]
final readonly class MoodChanged extends DomainEvent
{
    public function __construct(public Mood $mood, public ?Mood $previous = null, ?string $eventId = null, ?DateTimeImmutable $occurredAt = null)
    {
        parent::__construct($eventId, $occurredAt);
    }
}
