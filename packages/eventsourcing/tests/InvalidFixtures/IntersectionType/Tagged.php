<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Tests\InvalidFixtures\IntersectionType;

use Countable;
use Firefly\Domain\DomainEvent;
use Firefly\EventSourcing\Event\Attributes\EventType;
use Traversable;

/** An intersection only ever names classes, so no stored JSON value can satisfy it: refused at scan time. */
#[EventType(name: 'item.tagged')]
final readonly class Tagged extends DomainEvent
{
    /** @param Countable&Traversable<int, string> $tags */
    public function __construct(public Countable&Traversable $tags)
    {
        parent::__construct();
    }
}
