<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Tests\CapstoneFixtures;

use Firefly\Container\Attributes\Component;
use Firefly\EventSourcing\Event\StoredEvent;
use Firefly\EventSourcing\Upcasting\EventUpcaster;

/** order.placed v1 stored `amount`; v2 calls it `total`. Discovered as a bean. */
#[Component]
final class LegacyOrderPlacedUpcaster implements EventUpcaster
{
    public function eventType(): string
    {
        return 'order.placed';
    }

    public function fromVersion(): int
    {
        return 1;
    }

    public function priority(): int
    {
        return 0;
    }

    public function upcast(StoredEvent $event): StoredEvent
    {
        $payload = $event->payload;
        $payload['total'] = $payload['amount'] ?? 0;
        unset($payload['amount']);

        return $event->upcasted('order.placed', 2, $payload);
    }
}
