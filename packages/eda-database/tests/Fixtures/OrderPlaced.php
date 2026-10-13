<?php

declare(strict_types=1);

namespace Firefly\Eda\Database\Tests\Fixtures;

use Firefly\Domain\DomainEvent;

/** A domain event fixture: eventType() = 'OrderPlaced', payload = its public props. */
final readonly class OrderPlaced extends DomainEvent
{
    public function __construct(public int $orderId)
    {
        parent::__construct();
    }
}
