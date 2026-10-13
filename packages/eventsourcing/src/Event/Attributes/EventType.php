<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Event\Attributes;

use Attribute;

/**
 * Names a firefly/domain DomainEvent for the event store: the stable type it is stored under (`order.placed`, never
 * the class name, so a class can move), and its payload's schema version, which an EventUpcaster raises on read.
 * Compiled by EventSourcingScanner into the event-sourcing manifest (php artisan firefly:cache).
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class EventType
{
    public function __construct(
        public string $name,
        public int $version = 1,
    ) {}
}
