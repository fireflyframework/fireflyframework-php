<?php

declare(strict_types=1);

use Firefly\EventSourcing\Exception\ConcurrencyException;
use Firefly\EventSourcing\Exception\EventSourcingException;
use Firefly\EventSourcing\Store\DatabaseEventStore;
use Firefly\EventSourcing\Store\EventSourcingSchema;
use Firefly\EventSourcing\Store\EventStore;
use Firefly\EventSourcing\Tests\Support\EventSourcingDatabase;
use Firefly\EventSourcing\Tests\Support\EventStoreContract;
use Firefly\EventSourcing\Upcasting\UpcasterChain;
use Firefly\Kernel\Exception\Infrastructure\DuplicateKeyException;

foreach (EventStoreContract::cases() as $case => $assert) {
    it("SQLite database event store {$case}", function () use ($assert): void {
        $connection = EventSourcingDatabase::fresh(EventSourcingDatabase::sqlite());
        $assert(static fn (?UpcasterChain $upcasters): EventStore => new DatabaseEventStore($connection, upcasters: $upcasters ?? new UpcasterChain));
    });
}

it('turns the duplicate key of a racing append into a ConcurrencyException through the data layer translator', function (): void {
    $connection = EventSourcingDatabase::fresh(EventSourcingDatabase::sqlite());
    $store = new DatabaseEventStore($connection);
    $store->append('order-1', 'order', [EventStoreContract::event()], 0);

    // A writer that loaded version 0 appends after the stream moved to 1: the head check lets a LOWER expected
    // version through, so UNIQUE(aggregate_id, aggregate_version) is what refuses it.
    try {
        $store->append('order-1', 'order', [EventStoreContract::event('order.paid')], 0);
        $conflict = null;
    } catch (ConcurrencyException $caught) {
        $conflict = $caught;
    }

    expect($conflict)->not->toBeNull()
        ->and($conflict?->getPrevious())->toBeInstanceOf(DuplicateKeyException::class)
        ->and($conflict?->actualVersion)->toBe(1)
        ->and($connection->table(EventSourcingSchema::EVENTS)->count())->toBe(1);
});

it('refuses, before any write, a value longer than its column, as every driver would have to', function (string $field, Closure $append): void {
    $connection = EventSourcingDatabase::fresh(EventSourcingDatabase::sqlite());

    expect(fn () => $append(new DatabaseEventStore($connection)))->toThrow(EventSourcingException::class, $field)
        ->and($connection->table(EventSourcingSchema::EVENTS)->count())->toBe(0);
})->with([
    'aggregate id' => ['aggregateId', static fn (DatabaseEventStore $s) => $s->append(str_repeat('a', 129), 'order', [EventStoreContract::event()], 0)],
    'aggregate type' => ['aggregateType', static fn (DatabaseEventStore $s) => $s->append('a', str_repeat('t', 192), [EventStoreContract::event()], 0)],
    'event type' => ['eventType', static fn (DatabaseEventStore $s) => $s->append('a', 'order', [EventStoreContract::event(str_repeat('e', 192))], 0)],
]);

it('keeps every eventsourcing table across a second create and keeps the global head row', function (): void {
    $connection = EventSourcingDatabase::fresh(EventSourcingDatabase::sqlite());
    EventSourcingSchema::create($connection->getSchemaBuilder());

    expect($connection->table(EventSourcingSchema::HEAD)->pluck('position', 'name')->all())->toBe(['global' => 0])
        ->and($connection->getSchemaBuilder()->hasTable(EventSourcingSchema::SNAPSHOTS))->toBeTrue()
        ->and($connection->getSchemaBuilder()->hasTable(EventSourcingSchema::CHECKPOINTS))->toBeTrue();
});
