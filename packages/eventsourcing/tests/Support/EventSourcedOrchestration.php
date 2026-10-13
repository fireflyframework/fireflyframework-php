<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Tests\Support;

use Firefly\EventSourcing\Aggregate\EventSourcingSupport;
use Firefly\EventSourcing\Event\EventMapper;
use Firefly\EventSourcing\Event\EventTypeRegistry;
use Firefly\EventSourcing\Orchestration\EventSourcedExecutionPersistenceProvider;
use Firefly\EventSourcing\Snapshot\DatabaseSnapshotStore;
use Firefly\EventSourcing\Snapshot\MemorySnapshotStore;
use Firefly\EventSourcing\Snapshot\SnapshotPolicy;
use Firefly\EventSourcing\Store\DatabaseEventStore;
use Firefly\EventSourcing\Store\MemoryEventStore;
use Firefly\Orchestration\Persistence\DatabaseExecutionPersistenceProvider;
use Firefly\Orchestration\Persistence\MemoryExecutionPersistenceProvider;
use Firefly\Orchestration\Persistence\OrchestrationSchema;
use Illuminate\Database\Connection;

/**
 * The event-sourced execution store as the auto-configuration builds it, by hand: the memory event store with an
 * in-memory read model, or the database event store with W2's tables as the read model on the same connection.
 */
final class EventSourcedOrchestration
{
    public static function support(?Connection $connection = null, int $snapshotThreshold = 3): EventSourcingSupport
    {
        return new EventSourcingSupport(
            $connection === null ? new MemoryEventStore : new DatabaseEventStore($connection),
            new EventMapper(new EventTypeRegistry),
            $connection === null ? new MemorySnapshotStore : new DatabaseSnapshotStore($connection),
            new SnapshotPolicy(true, $snapshotThreshold, 2),
        );
    }

    public static function provider(?Connection $connection = null, int $snapshotThreshold = 3): EventSourcedExecutionPersistenceProvider
    {
        return self::from(self::support($connection, $snapshotThreshold), $connection);
    }

    public static function from(EventSourcingSupport $support, ?Connection $connection = null): EventSourcedExecutionPersistenceProvider
    {
        $readModel = $connection === null ? new MemoryExecutionPersistenceProvider : new DatabaseExecutionPersistenceProvider($connection);

        return new EventSourcedExecutionPersistenceProvider($support, $readModel, $connection);
    }

    public static function fresh(Connection $connection): Connection
    {
        EventSourcingDatabase::fresh($connection);
        OrchestrationSchema::drop($connection->getSchemaBuilder());
        OrchestrationSchema::create($connection->getSchemaBuilder());

        return $connection;
    }
}
