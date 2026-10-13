<?php

declare(strict_types=1);

use Firefly\EventSourcing\Event\StoredEvent;
use Firefly\EventSourcing\Store\CommitOrderNumbering;
use Firefly\EventSourcing\Store\DatabaseEventStore;
use Firefly\EventSourcing\Store\EventSourcingSchema;
use Firefly\EventSourcing\Tests\Support\EventSourcingDatabase;
use Firefly\EventSourcing\Tests\Support\EventStoreContract;

it('appends without a global position and numbers committed events when the stream is read', function (): void {
    $connection = EventSourcingDatabase::fresh(EventSourcingDatabase::sqlite());
    $store = new DatabaseEventStore($connection);
    $stored = $store->append('a', 'order', [EventStoreContract::event(), EventStoreContract::event('order.paid')], 0);

    expect(array_map(static fn (StoredEvent $e): ?int => $e->globalPosition, $stored))->toBe([null, null])
        ->and($connection->table(EventSourcingSchema::EVENTS)->whereNull('global_position')->count())->toBe(2)
        ->and(array_map(static fn (StoredEvent $e): ?int => $e->globalPosition, $store->readAll()))->toBe([1, 2])
        ->and($connection->table(EventSourcingSchema::HEAD)->value('position'))->toBe(2);
});

it('never numbers inside the caller\'s open transaction, so the head lock is never held for a unit of work', function (): void {
    $connection = EventSourcingDatabase::fresh(EventSourcingDatabase::sqlite());
    $store = new DatabaseEventStore($connection);

    $connection->beginTransaction();
    $store->append('a', 'order', [EventStoreContract::event()], 0);
    $inside = $store->readAll();
    $connection->commit();

    expect($inside)->toBe([])
        ->and(EventStoreContract::ids($store->readAll()))->toBe(['a']);
});

it('numbers in rounds of the batch size, keeping each aggregate in version order', function (): void {
    $connection = EventSourcingDatabase::fresh(EventSourcingDatabase::sqlite());
    $store = new DatabaseEventStore($connection, batchSize: 2);
    $store->append('a', 'order', [EventStoreContract::event(), EventStoreContract::event('order.paid')], 0);
    $store->append('b', 'order', [EventStoreContract::event()], 0);
    $store->append('a', 'order', [EventStoreContract::event('order.shipped'), EventStoreContract::event('order.closed')], 2);

    expect((new CommitOrderNumbering($connection, 2))->number())->toBe(5)
        ->and((new CommitOrderNumbering($connection, 2))->pending())->toBeFalse()
        ->and(array_map(static fn (StoredEvent $e): string => $e->aggregateId.$e->aggregateVersion, $store->readAll(0, 10)))->toBe(['a1', 'a2', 'b1', 'a3', 'a4']);
});
