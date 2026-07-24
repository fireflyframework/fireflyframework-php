<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Event;

use Firefly\Domain\DomainEvent;

/**
 * The bridge PORT (pyfly CommandEventPublisher): publish a committed DomainEvent as an integration event on the
 * broker bus. NoOpEventPublisher is the shipped default when no M9 EventPublisher is bound; EdaCommandEventPublisher
 * adapts to firefly/eda. The optional $destination overrides the event's routing for this call.
 */
interface CommandEventPublisher
{
    public function publish(DomainEvent $event, ?string $destination = null): void;
}
