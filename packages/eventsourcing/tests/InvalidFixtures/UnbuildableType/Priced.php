<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Tests\InvalidFixtures\UnbuildableType;

use Firefly\Domain\DomainEvent;
use Firefly\EventSourcing\Event\Attributes\EventType;

/** Stores $price as {"cents": ...}, which no constructor call can turn back into a Money: refused at scan time. */
#[EventType(name: 'priced.item')]
final readonly class Priced extends DomainEvent
{
    public function __construct(public string $sku, public Money $price)
    {
        parent::__construct();
    }
}
