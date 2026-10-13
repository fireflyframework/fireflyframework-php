<?php

declare(strict_types=1);

use Firefly\Orchestration\Dlq\DatabaseDeadLetterStore;
use Firefly\Orchestration\Dlq\DeadLetterEntry;
use Firefly\Orchestration\Tests\Support\DeadLetterStoreContract;
use Firefly\Orchestration\Tests\Support\IntegrationDatabase;

foreach (IntegrationDatabase::servers() as $server => [$driver, $variable, $extension]) {
    foreach (DeadLetterStoreContract::cases() as $case => $assert) {
        it("{$server} dead-letter store {$case}", function () use ($assert, $driver, $variable): void {
            $connection = IntegrationDatabase::fresh(IntegrationDatabase::connection($driver, $variable));
            try {
                $assert(new DatabaseDeadLetterStore($connection));
            } finally {
                $connection->disconnect();
            }
        })->group('integration')->skip(IntegrationDatabase::skipReason($variable, $extension) !== null, (string) IntegrationDatabase::skipReason($variable, $extension));
    }

    it("keeps an error message longer than a MySQL TEXT column on {$server}", function () use ($driver, $variable): void {
        $connection = IntegrationDatabase::fresh(IntegrationDatabase::connection($driver, $variable));
        try {
            $store = new DatabaseDeadLetterStore($connection);
            $entry = DeadLetterEntry::fromArray(['errorMessage' => str_repeat('é', 70_000)] + DeadLetterStoreContract::entry('d-1')->toArray());

            $store->save($entry);

            expect($store->findById('d-1')?->errorMessage)->toBe($entry->errorMessage);
        } finally {
            $connection->disconnect();
        }
    })->group('integration')->skip(IntegrationDatabase::skipReason($variable, $extension) !== null, (string) IntegrationDatabase::skipReason($variable, $extension));
}
