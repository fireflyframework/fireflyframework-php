<?php

declare(strict_types=1);

namespace Firefly\Eda\Postgres\Tests\Fixtures;

use Firefly\Domain\DomainEvent;

/** A concrete domain event fixture: eventType() = 'OutboxSampleEvent', payload = its public props. */
final readonly class OutboxSampleEvent extends DomainEvent
{
    public function __construct(public int $orderId, public string $status)
    {
        parent::__construct();
    }
}
