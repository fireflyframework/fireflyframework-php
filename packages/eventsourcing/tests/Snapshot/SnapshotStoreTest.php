<?php

declare(strict_types=1);

use Firefly\EventSourcing\Snapshot\DatabaseSnapshotStore;
use Firefly\EventSourcing\Snapshot\MemorySnapshotStore;
use Firefly\EventSourcing\Tests\Support\EventSourcingDatabase;
use Firefly\EventSourcing\Tests\Support\SnapshotStoreContract;

foreach (SnapshotStoreContract::cases() as $case => $assert) {
    it("memory snapshot store {$case}", function () use ($assert): void {
        $assert(new MemorySnapshotStore);
    });

    it("SQLite snapshot store {$case}", function () use ($assert): void {
        $assert(new DatabaseSnapshotStore(EventSourcingDatabase::fresh(EventSourcingDatabase::sqlite())));
    });
}
