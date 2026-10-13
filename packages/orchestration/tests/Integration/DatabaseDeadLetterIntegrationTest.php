<?php

declare(strict_types=1);

use Firefly\Orchestration\Dlq\DatabaseDeadLetterStore;
use Firefly\Orchestration\Dlq\DeadLetterEntry;
use Firefly\Orchestration\Tests\Support\DeadLetterStoreContract;
use Firefly\Orchestration\Tests\Support\IntegrationDatabase;

foreach (IntegrationDatabase::lanes() as $lane) {
    foreach (DeadLetterStoreContract::cases() as $case => $assert) {
        it("{$lane} dead-letter store {$case}", function () use ($assert, $lane): void {
            $connection = IntegrationDatabase::fresh(IntegrationDatabase::connection($lane));
            try {
                $assert(new DatabaseDeadLetterStore($connection));
            } finally {
                $connection->disconnect();
            }
        })->group('integration')->skip(IntegrationDatabase::skipReason($lane) !== null, (string) IntegrationDatabase::skipReason($lane));
    }

    it("keeps an error message longer than a MySQL TEXT column on {$lane}", function () use ($lane): void {
        $connection = IntegrationDatabase::fresh(IntegrationDatabase::connection($lane));
        try {
            $store = new DatabaseDeadLetterStore($connection);
            $entry = DeadLetterEntry::fromArray(['errorMessage' => str_repeat('é', 70_000)] + DeadLetterStoreContract::entry('d-1')->toArray());

            $store->save($entry);

            expect($store->findById('d-1')?->errorMessage)->toBe($entry->errorMessage);
        } finally {
            $connection->disconnect();
        }
    })->group('integration')->skip(IntegrationDatabase::skipReason($lane) !== null, (string) IntegrationDatabase::skipReason($lane));
}
