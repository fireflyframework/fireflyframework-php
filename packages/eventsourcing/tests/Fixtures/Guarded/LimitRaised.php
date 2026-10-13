<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Tests\Fixtures\Guarded;

use DateTimeImmutable;
use Firefly\Domain\DomainEvent;
use Firefly\EventSourcing\Event\Attributes\EventType;
use InvalidArgumentException;

/** A convention-mapped event whose constructor guards its own invariant, so a stored payload can fail it. */
#[EventType(name: 'limit.raised')]
final readonly class LimitRaised extends DomainEvent
{
    public function __construct(public int $limit, ?string $eventId = null, ?DateTimeImmutable $occurredAt = null)
    {
        if ($limit < 0) {
            throw new InvalidArgumentException("A limit cannot be negative; got {$limit} for tenant secret-tenant-7.");
        }

        parent::__construct($eventId, $occurredAt);
    }
}
