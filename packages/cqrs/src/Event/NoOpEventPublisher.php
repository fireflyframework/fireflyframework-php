<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Event;

use Firefly\Domain\DomainEvent;
use Psr\Log\LoggerInterface;

/**
 * The default CommandEventPublisher when no M9 EventPublisher is bound: it drops the integration event (optionally
 * logging at debug). So cqrs installed WITHOUT firefly/eda still boots and simply emits no integration events.
 */
final class NoOpEventPublisher implements CommandEventPublisher
{
    public function __construct(private readonly ?LoggerInterface $logger = null) {}

    public function publish(DomainEvent $event, ?string $destination = null): void
    {
        $this->logger?->debug("CQRS integration-event bridge is a no-op (no EventPublisher bound); dropping [{$event->eventType()}].");
    }
}
