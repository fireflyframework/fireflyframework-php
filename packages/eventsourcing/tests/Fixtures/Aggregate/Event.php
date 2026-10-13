<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Tests\Fixtures\Aggregate;

use Firefly\Domain\DomainEvent;

/** An event whose short name is "Event", so its convention handler is applyEvent(). */
final readonly class Event extends DomainEvent
{
    public function __construct(public string $note)
    {
        parent::__construct();
    }
}
