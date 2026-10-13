<?php

declare(strict_types=1);

use Firefly\EventSourcing\Snapshot\DatabaseSnapshotStore;
use Firefly\EventSourcing\Tests\Support\EventSourcingDatabase;
use Firefly\EventSourcing\Tests\Support\SnapshotStoreContract;

foreach (EventSourcingDatabase::lanes() as $lane) {
    foreach (SnapshotStoreContract::cases() as $case => $assert) {
        it("{$lane} snapshot store {$case}", function () use ($assert, $lane): void {
            $connection = EventSourcingDatabase::fresh(EventSourcingDatabase::connection($lane));
            try {
                $assert(new DatabaseSnapshotStore($connection));
            } finally {
                $connection->disconnect();
            }
        })->group('integration')->skip(EventSourcingDatabase::skipReason($lane) !== null, (string) EventSourcingDatabase::skipReason($lane));
    }
}
