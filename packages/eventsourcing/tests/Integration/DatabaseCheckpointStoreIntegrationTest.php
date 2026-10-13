<?php

declare(strict_types=1);

use Firefly\EventSourcing\Projection\DatabaseCheckpointStore;
use Firefly\EventSourcing\Tests\Support\CheckpointStoreContract;
use Firefly\EventSourcing\Tests\Support\EventSourcingDatabase;

foreach (EventSourcingDatabase::lanes() as $lane) {
    foreach (CheckpointStoreContract::cases() as $case => $assert) {
        it("{$lane} checkpoint store {$case}", function () use ($assert, $lane): void {
            $connection = EventSourcingDatabase::fresh(EventSourcingDatabase::connection($lane));
            try {
                $assert(new DatabaseCheckpointStore($connection));
            } finally {
                $connection->disconnect();
            }
        })->group('integration')->skip(EventSourcingDatabase::skipReason($lane) !== null, (string) EventSourcingDatabase::skipReason($lane));
    }

    it("keeps a full-width projection name and case-distinct names apart on {$lane}", function () use ($lane): void {
        $connection = EventSourcingDatabase::fresh(EventSourcingDatabase::connection($lane));
        try {
            $store = new DatabaseCheckpointStore($connection);
            $wide = str_repeat('p', 191);

            expect($store->advance($wide, 0, 2))->toBeTrue()
                ->and($store->advance('Orders', 0, 1))->toBeTrue()
                ->and($store->advance('orders', 0, 5))->toBeTrue()
                ->and([$store->get($wide)->position, $store->get('Orders')->position, $store->get('orders')->position])->toBe([2, 1, 5]);
        } finally {
            $connection->disconnect();
        }
    })->group('integration')->skip(EventSourcingDatabase::skipReason($lane) !== null, (string) EventSourcingDatabase::skipReason($lane));
}
