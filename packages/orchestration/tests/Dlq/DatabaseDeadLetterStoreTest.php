<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\Exception\OrchestrationException;
use Firefly\Orchestration\Dlq\DatabaseDeadLetterStore;
use Firefly\Orchestration\Dlq\DeadLetterEntry;
use Firefly\Orchestration\Tests\Support\DeadLetterStoreContract;
use Firefly\Orchestration\Tests\Support\IntegrationDatabase;

foreach (DeadLetterStoreContract::cases() as $case => $assert) {
    it("SQLite dead-letter store {$case}", function () use ($assert): void {
        $assert(new DatabaseDeadLetterStore(IntegrationDatabase::fresh(IntegrationDatabase::sqlite())));
    });
}

it('refuses, before any write, a dead letter whose identity outgrows its column', function (string $field, string $id, string $name, string $correlationId): void {
    $connection = IntegrationDatabase::fresh(IntegrationDatabase::sqlite());
    $store = new DatabaseDeadLetterStore($connection);

    expect(fn () => $store->save(DeadLetterStoreContract::entry($id, $name, $correlationId)))
        ->toThrow(OrchestrationException::class, $field)
        ->and($store->count())->toBe(0);
})->with([
    'id' => ['id', str_repeat('d', 65), 'order', 'c-1'],
    'execution name' => ['executionName', 'd-1', str_repeat('n', 192), 'c-1'],
    'correlation id' => ['correlationId', 'd-1', 'order', str_repeat('c', 65)],
]);

it('keeps an error message of any length', function (): void {
    $store = new DatabaseDeadLetterStore(IntegrationDatabase::fresh(IntegrationDatabase::sqlite()));
    $entry = DeadLetterEntry::fromArray(['errorMessage' => str_repeat('é', 70_000)] + DeadLetterStoreContract::entry('d-1')->toArray());

    $store->save($entry);

    expect($store->findById('d-1')?->errorMessage)->toBe($entry->errorMessage);
});
