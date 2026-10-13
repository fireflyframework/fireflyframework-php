<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Tests\Fixtures\Unions;

use Firefly\Domain\DomainEvent;
use Firefly\EventSourcing\Event\Attributes\EventType;

/** Unions of JSON scalars and array only: each stored value is passed back as is, so the scan accepts them. */
#[EventType(name: 'stock.counted')]
final readonly class Counted extends DomainEvent
{
    /** @param array<int, string>|string $bins */
    public function __construct(public int|string $quantity, public float|bool|null $flag, public array|string $bins)
    {
        parent::__construct();
    }
}
