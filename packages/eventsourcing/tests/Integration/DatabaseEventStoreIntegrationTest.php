<?php

declare(strict_types=1);

use Firefly\EventSourcing\Event\NewEvent;
use Firefly\EventSourcing\Event\StoredEvent;
use Firefly\EventSourcing\Store\DatabaseEventStore;
use Firefly\EventSourcing\Store\EventStore;
use Firefly\EventSourcing\Tests\Support\EventSourcingDatabase;
use Firefly\EventSourcing\Tests\Support\EventStoreContract;
use Firefly\EventSourcing\Upcasting\UpcasterChain;

foreach (EventSourcingDatabase::lanes() as $lane) {
    foreach (EventStoreContract::cases() as $case => $assert) {
        it("{$lane} database event store {$case}", function () use ($assert, $lane): void {
            $connection = EventSourcingDatabase::fresh(EventSourcingDatabase::connection($lane));
            try {
                $assert(static fn (?UpcasterChain $upcasters): EventStore => new DatabaseEventStore($connection, upcasters: $upcasters ?? new UpcasterChain));
            } finally {
                $connection->disconnect();
            }
        })->group('integration')->skip(EventSourcingDatabase::skipReason($lane) !== null, (string) EventSourcingDatabase::skipReason($lane));
    }

    it("keeps case-distinct ids as two streams and full-width ids on {$lane}", function () use ($lane): void {
        $connection = EventSourcingDatabase::fresh(EventSourcingDatabase::connection($lane));
        try {
            $store = new DatabaseEventStore($connection);
            $store->append('Order-1', 'order', [EventStoreContract::event()], 0);
            $store->append('order-1', 'order', [EventStoreContract::event()], 0);
            $wide = str_repeat('i', 128);
            $store->append($wide, str_repeat('t', 191), [EventStoreContract::event(str_repeat('e', 191))], 0);

            expect($store->version('Order-1'))->toBe(1)
                ->and($store->version('order-1'))->toBe(1)
                ->and($store->load($wide)->events[0]->eventType)->toBe(str_repeat('e', 191));
        } finally {
            $connection->disconnect();
        }
    })->group('integration')->skip(EventSourcingDatabase::skipReason($lane) !== null, (string) EventSourcingDatabase::skipReason($lane));
}

/*
 * THE VISIBILITY CAVEAT, on a real server: a writer holds an append open while a reader appends and reads the
 * global stream. The reader must not see the open event, must not wait for it (READPAST on SQL Server), and must
 * find it ABOVE everything it already read once it commits — never below.
 */
foreach (EventSourcingDatabase::serverLanes() as $lane) {
    it("never lets a reader skip an append that commits late on {$lane}", function () use ($lane): void {
        $writerConnection = EventSourcingDatabase::fresh(EventSourcingDatabase::connection($lane));
        $readerConnection = EventSourcingDatabase::connection($lane);
        try {
            $writer = new DatabaseEventStore($writerConnection);
            $reader = new DatabaseEventStore($readerConnection);

            $writerConnection->beginTransaction();
            $writer->append('late', 'race', [new NewEvent('race.happened', ['n' => 1])], 0);

            $reader->append('early', 'race', [new NewEvent('race.happened', ['n' => 2])], 0);
            $before = $reader->readAll(0);

            $writerConnection->commit();
            $after = $reader->readAll(1);

            expect(array_map(static fn (StoredEvent $e): array => [$e->aggregateId, $e->globalPosition], $before))->toBe([['early', 1]])
                ->and(array_map(static fn (StoredEvent $e): array => [$e->aggregateId, $e->globalPosition], $after))->toBe([['late', 2]])
                ->and($reader->currentPosition())->toBe(2);
        } finally {
            if ($writerConnection->transactionLevel() > 0) {
                $writerConnection->rollBack();
            }
            $writerConnection->disconnect();
            $readerConnection->disconnect();
        }
    })->group('integration')->skip(EventSourcingDatabase::skipReason($lane) !== null, (string) EventSourcingDatabase::skipReason($lane));
}
