<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\Exception\OrchestrationException;
use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Persistence\DatabaseExecutionPersistenceProvider;
use Firefly\Orchestration\Persistence\OrchestrationSchema;
use Firefly\Orchestration\Saga\Builder\SagaBuilder;
use Firefly\Orchestration\Tests\Support\ExecutionPersistenceContract;
use Firefly\Orchestration\Tests\Support\IntegrationDatabase;
use Firefly\Orchestration\Tests\Support\TestEngines;

foreach (ExecutionPersistenceContract::cases() as $case => $assert) {
    it("SQLite database persistence {$case}", function () use ($assert): void {
        $assert(new DatabaseExecutionPersistenceProvider(IntegrationDatabase::fresh(IntegrationDatabase::sqlite())));
    });
}

it('keeps one row per execution and checkpoints a saga through it', function (): void {
    $connection = IntegrationDatabase::fresh(IntegrationDatabase::sqlite());
    $engines = TestEngines::create(persistence: new DatabaseExecutionPersistenceProvider($connection));
    $saga = SagaBuilder::saga('db')->step('a')->handler(static fn (): array => ['n' => 1])->add()->step('b')->dependsOn('a')->handler(static fn (): string => 'b')->add()->build();

    $result = $engines->sagas->execute($saga, correlationId: 'db-1');

    expect($result->isSuccess())->toBeTrue()
        ->and($connection->table(OrchestrationSchema::EXECUTIONS)->count())->toBe(1)
        ->and($connection->table(OrchestrationSchema::EXECUTIONS)->value('status'))->toBe('COMPLETED')
        ->and($engines->persistence->findById('db-1')?->stepResults)->toBe(['a' => ['n' => 1], 'b' => 'b'])
        ->and($engines->persistence->findById('db-1')?->status)->toBe(ExecutionStatus::Completed);
});

it('refuses, before any write, a value longer than its column, as every driver would have to', function (string $field, array $overrides): void {
    $connection = IntegrationDatabase::fresh(IntegrationDatabase::sqlite());
    $provider = new DatabaseExecutionPersistenceProvider($connection);
    /** @var array<string, mixed> $overrides */
    expect(fn () => $provider->save(ExecutionPersistenceContract::state('c-1', overrides: $overrides)))
        ->toThrow(OrchestrationException::class, $field)
        ->and($connection->table(OrchestrationSchema::EXECUTIONS)->count())->toBe(0)
        ->and($connection->table(OrchestrationSchema::SEARCH_ATTRIBUTES)->count())->toBe(0);
})->with([
    'correlation id' => ['correlationId', ['correlationId' => str_repeat('c', 65)]],
    'parent correlation id' => ['parentCorrelationId', ['parentCorrelationId' => str_repeat('p', 65)]],
    'execution name' => ['executionName', ['executionName' => str_repeat('n', 192)]],
    'search-attribute key' => ['searchAttributes', ['searchAttributes' => [str_repeat('k', 129) => 'v']]],
    'search-attribute value' => ['searchAttributes.customer', ['searchAttributes' => ['customer' => str_repeat('é', 192)]]],
]);

it('accepts values exactly as long as their columns, counting characters rather than bytes', function (): void {
    $provider = new DatabaseExecutionPersistenceProvider(IntegrationDatabase::fresh(IntegrationDatabase::sqlite()));
    $id = str_repeat('i', 64);
    $state = ExecutionPersistenceContract::state($id, overrides: ['executionName' => str_repeat('ñ', 191), 'searchAttributes' => [str_repeat('k', 128) => str_repeat('é', 191)]]);

    $provider->save($state);

    expect($provider->findById($id)?->toArray())->toBe($state->toArray())
        ->and(ExecutionPersistenceContract::ids($provider->findBySearchAttributes([str_repeat('k', 128) => str_repeat('é', 191)])))->toBe([$id]);
});
