<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\ScannerFixtures;

use Firefly\Domain\DomainEvent;

final readonly class WidgetArchived extends DomainEvent
{
    public function __construct(public int $id)
    {
        parent::__construct();
    }
}
