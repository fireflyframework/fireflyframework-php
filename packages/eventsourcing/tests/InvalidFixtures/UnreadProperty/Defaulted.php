<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Tests\InvalidFixtures\UnreadProperty;

use Firefly\Domain\DomainEvent;
use Firefly\EventSourcing\Event\Attributes\EventType;

/** The event stores $qty but its constructor takes $count: a reload would silently rebuild qty from the default. */
#[EventType(name: 'defaulted.property')]
final readonly class Defaulted extends DomainEvent
{
    public int $qty;

    public function __construct(int $count = 1)
    {
        parent::__construct();
        $this->qty = $count;
    }
}
