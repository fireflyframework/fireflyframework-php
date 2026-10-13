<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Tests\InvalidFixtures\UnionType;

use DateTimeImmutable;
use Firefly\Domain\DomainEvent;
use Firefly\EventSourcing\Event\Attributes\EventType;
use Firefly\EventSourcing\Tests\InvalidFixtures\UnbuildableType\Money;

/**
 * $price would be stored as {"cents": ...} and handed back raw to a Money|int parameter, and $effective would come
 * back as a string instead of a date: both unions name a class, so the scan refuses them.
 */
#[EventType(name: 'item.repriced')]
final readonly class Repriced extends DomainEvent
{
    public function __construct(public string $sku, public Money|int $price, public DateTimeImmutable|string|null $effective)
    {
        parent::__construct();
    }
}
