<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\Exception\OrchestrationException;
use Firefly\Orchestration\Persistence\DatabaseExecutionPersistenceProvider;
use Firefly\Orchestration\Persistence\OrchestrationSchema;
use Firefly\Orchestration\Tests\Support\ExecutionPersistenceContract;
use Firefly\Orchestration\Tests\Support\IntegrationDatabase;

foreach (IntegrationDatabase::servers() as $server => [$driver, $variable, $extension]) {
    foreach (ExecutionPersistenceContract::cases() as $case => $assert) {
        it("{$server} database persistence {$case}", function () use ($assert, $driver, $variable): void {
            $connection = IntegrationDatabase::fresh(IntegrationDatabase::connection($driver, $variable));
            try {
                $assert(new DatabaseExecutionPersistenceProvider($connection));
            } finally {
                $connection->disconnect();
            }
        })->group('integration')->skip(IntegrationDatabase::skipReason($variable, $extension) !== null, (string) IntegrationDatabase::skipReason($variable, $extension));
    }

    it("keeps full-width ids, names and search attributes and refuses wider ones on {$server}", function () use ($driver, $variable): void {
        $connection = IntegrationDatabase::fresh(IntegrationDatabase::connection($driver, $variable));
        try {
            $provider = new DatabaseExecutionPersistenceProvider($connection);
            $id = str_repeat('i', 64);
            $state = ExecutionPersistenceContract::state($id, overrides: ['executionName' => str_repeat('ñ', 191), 'searchAttributes' => [str_repeat('k', 128) => str_repeat('é', 191)]]);

            $provider->save($state);

            expect($provider->findById($id)?->toArray())->toBe($state->toArray())
                ->and(ExecutionPersistenceContract::ids($provider->findBySearchAttributes([str_repeat('k', 128) => str_repeat('é', 191)])))->toBe([$id])
                ->and(fn () => $provider->save(ExecutionPersistenceContract::state('wide', overrides: ['searchAttributes' => ['customer' => str_repeat('é', 192)]])))->toThrow(OrchestrationException::class, 'searchAttributes.customer')
                ->and($connection->table(OrchestrationSchema::EXECUTIONS)->count())->toBe(1);
        } finally {
            $connection->disconnect();
        }
    })->group('integration')->skip(IntegrationDatabase::skipReason($variable, $extension) !== null, (string) IntegrationDatabase::skipReason($variable, $extension));
}
