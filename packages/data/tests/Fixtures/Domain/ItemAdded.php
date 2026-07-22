<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\Domain;

use Firefly\Domain\DomainEvent;

/**
 * A flat readonly domain event raised by the Basket aggregate. Carries the added SKU; the base supplies eventId +
 * occurredAt. Used to assert publish timing (after commit) and ordering.
 */
final readonly class ItemAdded extends DomainEvent
{
    public function __construct(public string $sku)
    {
        parent::__construct();
    }
}
