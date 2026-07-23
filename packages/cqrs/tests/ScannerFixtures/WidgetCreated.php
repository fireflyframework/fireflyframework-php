<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\ScannerFixtures;

use Firefly\Cqrs\Attributes\PublishDomainEvent;
use Firefly\Domain\DomainEvent;

#[PublishDomainEvent('widgets.events')]
final readonly class WidgetCreated extends DomainEvent
{
    public function __construct(public string $name)
    {
        parent::__construct();
    }
}
