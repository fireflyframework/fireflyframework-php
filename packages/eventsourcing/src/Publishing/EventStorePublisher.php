<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Publishing;

use Firefly\EventSourcing\Event\StoredEvent;
use Illuminate\Database\ConnectionInterface;

/**
 * How appended events reach firefly/eda. A store calls BOTH hooks on every append, and every adapter implements
 * exactly one of them. That is why an event is never published twice:
 *
 *  - inTransaction(): inside the append's transaction, after the rows are written. The outbox adapter INSERTs its
 *    firefly_eda_outbox rows here, so they commit or roll back with the events. A throw undoes the append.
 *  - afterTransaction(): after the append's own transaction returned. The after-commit adapter publishes here, at
 *    once when no outer transaction is open, else through Connection::afterCommit().
 *
 * $connection is the store's connection, null for the memory store.
 */
interface EventStorePublisher
{
    /** @param list<StoredEvent> $events */
    public function inTransaction(?ConnectionInterface $connection, array $events): void;

    /** @param list<StoredEvent> $events */
    public function afterTransaction(?ConnectionInterface $connection, array $events): void;
}
