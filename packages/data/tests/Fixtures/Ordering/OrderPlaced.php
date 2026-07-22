<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\Ordering;

use Firefly\Domain\DomainEvent;

final readonly class OrderPlaced extends DomainEvent
{
    public function __construct(public string $status)
    {
        parent::__construct();
    }
}
