<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Publishing;

use Firefly\Eda\EventPublisher;
use Illuminate\Database\ConnectionInterface;

/**
 * Same-transaction publishing, chosen when the bound eda EventPublisher is an outbox writer
 * (firefly.eda.provider=database|postgres). Inside the append's transaction it hands every event to that writer,
 * which INSERTs a firefly_eda_outbox row on the same connection. The row commits or rolls back with the events, and
 * the outbox consumer or relay delivers it afterwards: at-least-once, deduplicate on `x-event-id`.
 * EventSourcingSettings refuses the boot when the store and the outbox are on different connections. Aggregate types
 * in $excludedAggregateTypes are skipped.
 */
final class OutboxEventStorePublisher implements EventStorePublisher
{
    /**
     * @param  list<string>  $excludedAggregateTypes
     */
    public function __construct(
        private readonly EventPublisher $outbox,
        private readonly string $destination,
        private readonly array $excludedAggregateTypes = [],
    ) {}

    public function inTransaction(?ConnectionInterface $connection, array $events): void
    {
        foreach ($events as $event) {
            if (! in_array($event->aggregateType, $this->excludedAggregateTypes, true)) {
                $this->outbox->publish($this->destination, $event->eventType, $event->payload, PublishedHeaders::for($event));
            }
        }
    }

    public function afterTransaction(?ConnectionInterface $connection, array $events): void {}
}
