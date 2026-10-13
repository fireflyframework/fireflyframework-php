<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Publishing;

use Illuminate\Database\ConnectionInterface;

/** firefly.eventsourcing.publish.enabled=false (the default): appended events stay in the store. */
final class NoOpEventStorePublisher implements EventStorePublisher
{
    public function inTransaction(?ConnectionInterface $connection, array $events): void {}

    public function afterTransaction(?ConnectionInterface $connection, array $events): void {}
}
