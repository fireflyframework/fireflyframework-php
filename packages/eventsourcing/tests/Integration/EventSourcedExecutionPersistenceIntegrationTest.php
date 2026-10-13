<?php

declare(strict_types=1);

use Firefly\EventSourcing\Tests\Support\EventSourcedOrchestration;
use Firefly\EventSourcing\Tests\Support\EventSourcingDatabase;
use Firefly\Orchestration\Tests\Support\ExecutionPersistenceContract;

foreach (EventSourcingDatabase::lanes() as $lane) {
    foreach (ExecutionPersistenceContract::cases() as $case => $assert) {
        it("{$lane} event-sourced persistence {$case}", function () use ($assert, $lane): void {
            $connection = EventSourcedOrchestration::fresh(EventSourcingDatabase::connection($lane));
            try {
                $assert(EventSourcedOrchestration::provider($connection));
            } finally {
                $connection->disconnect();
            }
        })->group('integration')->skip(EventSourcingDatabase::skipReason($lane) !== null, (string) EventSourcingDatabase::skipReason($lane));
    }
}
