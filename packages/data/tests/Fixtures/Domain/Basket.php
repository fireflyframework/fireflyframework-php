<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\Domain;

use Firefly\Domain\AggregateRoot;

/**
 * A persistence-free domain aggregate (extends the T1 AggregateRoot, which implements RecordsDomainEvents).
 * addItem() raises an ItemAdded event into the pending buffer; the framework drains it via pullEvents() after the
 * transaction commits. Not an Eloquent model — the purist path; the T17 capstone proves the active-record path
 * (a Model use HasDomainEvents that is BOTH persisted and tracked by the plain base save()).
 */
final class Basket extends AggregateRoot
{
    public function addItem(string $sku): void
    {
        $this->raiseEvent(new ItemAdded($sku));
    }
}
