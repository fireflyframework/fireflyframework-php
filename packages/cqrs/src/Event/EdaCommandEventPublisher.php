<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Event;

use Firefly\Cqrs\Correlation\CorrelationContext;
use Firefly\Domain\DomainEvent;
use Firefly\Eda\EventPublisher;

/**
 * Adapts a committed Firefly\Domain\DomainEvent to the M9 EventPublisher broker port (pyfly EdaCommandEventPublisher,
 * field-for-field): eventType = $event->eventType() (concrete class short name); payload = get_object_vars($event)
 * (public props only, seen from outside the event — reflection-free, matching DomainEvent's own no-reflection idiom);
 * destination resolved as explicit arg -> #[PublishDomainEvent] map (compiled by HandlerScanner) -> defaultDestination
 * (firefly.cqrs.default_destination, 'cqrs.events'). Stamps the active correlation id into x-correlation-id.
 */
final class EdaCommandEventPublisher implements CommandEventPublisher
{
    /**
     * @param  array<string,string>  $destinations  eventClass => destination
     */
    public function __construct(
        private readonly EventPublisher $producer,
        private readonly string $defaultDestination = 'cqrs.events',
        private readonly array $destinations = [],
        private readonly ?CorrelationContext $correlation = null,
    ) {}

    public function publish(DomainEvent $event, ?string $destination = null): void
    {
        $target = $destination ?? $this->destinations[$event::class] ?? $this->defaultDestination;

        $headers = [];
        $correlationId = $this->correlation?->currentId();
        if ($correlationId !== null) {
            $headers['x-correlation-id'] = $correlationId;
        }

        // get_object_vars() from outside the event's scope captures its PUBLIC properties (constructor-promoted
        // + the inherited eventId/occurredAt) — the same reflection-free idiom BeanValidator::validateObject()
        // uses. Rebuilt with an explicit string key cast: PHPStan's get_object_vars() stub types the key as
        // int|string, but object property names are always strings.
        $payload = [];
        foreach (get_object_vars($event) as $property => $value) {
            $payload[(string) $property] = $value;
        }

        $this->producer->publish($target, $event->eventType(), $payload, $headers);
    }
}
