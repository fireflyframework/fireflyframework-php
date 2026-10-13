<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Publishing;

use Firefly\Eda\EventPublisher;
use Firefly\EventSourcing\Exception\EventSourcingException;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * After-commit publishing, for any eda provider that is not an outbox (memory, queue, rabbitmq, kafka). The events
 * go out only once the append is durable:
 *
 *  - at once when no transaction is open on the store's connection (the append's own transaction has committed);
 *  - otherwise through Connection::afterCommit(), when the caller's outermost transaction commits.
 *
 * A rollback drops them. A crash between the commit and the publish loses the publish; that is the price of not
 * using the outbox, and the module guide says so.
 */
final class AfterCommitEventStorePublisher implements EventStorePublisher
{
    /**
     * @param  list<string>  $excludedAggregateTypes
     */
    public function __construct(
        private readonly EventPublisher $publisher,
        private readonly string $destination,
        private readonly array $excludedAggregateTypes = [],
    ) {}

    public function inTransaction(?ConnectionInterface $connection, array $events): void {}

    public function afterTransaction(?ConnectionInterface $connection, array $events): void
    {
        $publishable = array_values(array_filter($events, fn ($event): bool => ! in_array($event->aggregateType, $this->excludedAggregateTypes, true)));
        if ($publishable === []) {
            return;
        }

        $publish = function () use ($publishable): void {
            foreach ($publishable as $event) {
                $this->publisher->publish($this->destination, $event->eventType, $event->payload, PublishedHeaders::for($event));
            }
        };

        if ($connection === null || $connection->transactionLevel() === 0) {
            $publish();

            return;
        }

        if (! $connection instanceof Connection) {
            throw new EventSourcingException('Cannot defer publishing to the end of the open transaction: the store connection is not an Illuminate\Database\Connection.', 'EVENT_PUBLISH_DEFERRAL');
        }

        try {
            $connection->afterCommit($publish);
        } catch (RuntimeException $failure) {
            throw new EventSourcingException('Cannot defer publishing to the end of the open transaction: the connection has no transactions manager.', 'EVENT_PUBLISH_DEFERRAL', 500, $failure);
        }
    }
}
