<?php

declare(strict_types=1);

use Firefly\Eda\Bus\SubscriberRegistry;
use Firefly\Eda\Database\DatabaseEventPublisher;
use Firefly\Eda\Database\Outbox\OutboxSchema;
use Firefly\EventSourcing\Publishing\OutboxEventStorePublisher;
use Firefly\EventSourcing\Store\DatabaseEventStore;
use Firefly\EventSourcing\Store\EventSourcingSchema;
use Firefly\EventSourcing\Tests\Support\EventSourcingDatabase;
use Firefly\EventSourcing\Tests\Support\EventStoreContract;
use Illuminate\Database\Schema\Blueprint;

foreach (EventSourcingDatabase::lanes() as $lane) {
    it("commits and rolls back the outbox rows with the events on {$lane}", function () use ($lane): void {
        $connection = EventSourcingDatabase::fresh(EventSourcingDatabase::connection($lane));
        try {
            $connection->getSchemaBuilder()->dropIfExists(OutboxSchema::TABLE);
            $connection->getSchemaBuilder()->create(OutboxSchema::TABLE, static fn (Blueprint $table) => OutboxSchema::blueprint($table));
            $store = new DatabaseEventStore($connection, publisher: new OutboxEventStorePublisher(new DatabaseEventPublisher($connection, new SubscriberRegistry), 'orders'));

            $store->append('o-1', 'order', [EventStoreContract::event(), EventStoreContract::event('order.paid')], 0);
            try {
                $connection->transaction(static function () use ($store): void {
                    $store->append('o-2', 'order', [EventStoreContract::event()], 0);
                    throw new RuntimeException('rolled back');
                });
            } catch (RuntimeException) {
            }

            expect($connection->table(OutboxSchema::TABLE)->count())->toBe(2)
                ->and($connection->table(EventSourcingSchema::EVENTS)->count())->toBe(2);
        } finally {
            $connection->getSchemaBuilder()->dropIfExists(OutboxSchema::TABLE);
            $connection->disconnect();
        }
    })->group('integration')->skip(EventSourcingDatabase::skipReason($lane) !== null, (string) EventSourcingDatabase::skipReason($lane));
}
