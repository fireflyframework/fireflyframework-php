<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\Exception\OrchestrationException;
use Firefly\Orchestration\Persistence\DatabaseExecutionPersistenceProvider;
use Firefly\Orchestration\Persistence\OrchestrationSchema;
use Firefly\Orchestration\Tests\Support\ExecutionPersistenceContract;
use Firefly\Orchestration\Tests\Support\IntegrationDatabase;

foreach (IntegrationDatabase::lanes() as $lane) {
    foreach (ExecutionPersistenceContract::cases() as $case => $assert) {
        it("{$lane} database persistence {$case}", function () use ($assert, $lane): void {
            $connection = IntegrationDatabase::fresh(IntegrationDatabase::connection($lane));
            try {
                $assert(new DatabaseExecutionPersistenceProvider($connection));
            } finally {
                $connection->disconnect();
            }
        })->group('integration')->skip(IntegrationDatabase::skipReason($lane) !== null, (string) IntegrationDatabase::skipReason($lane));
    }

    it("keeps full-width ids, names and search attributes and refuses wider ones on {$lane}", function () use ($lane): void {
        $connection = IntegrationDatabase::fresh(IntegrationDatabase::connection($lane));
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
    })->group('integration')->skip(IntegrationDatabase::skipReason($lane) !== null, (string) IntegrationDatabase::skipReason($lane));
}

// The interleaving below needs a server with row-level locking; sqlite has one writer per file, so there it is only a wait.
foreach (IntegrationDatabase::serverLanes() as $lane) {
    it("saves two new executions with search attributes from two workers at once without a gap-lock wait on {$lane}", function () use ($lane): void {
        $first = IntegrationDatabase::fresh(IntegrationDatabase::connection($lane));
        $second = IntegrationDatabase::connection($lane);
        try {
            // A lock wait (the first half of the MySQL/MariaDB deadlock) surfaces in one second instead of hanging.
            match ($lane) {
                'mysql', 'mariadb' => $second->statement('SET SESSION innodb_lock_wait_timeout = 1'),
                'pgsql' => $second->statement("SET lock_timeout = '1s'"),
                // A prepared SET is undone when its statement ends on SQL Server, so it is sent unprepared there.
                'sqlsrv' => $second->unprepared('SET LOCK_TIMEOUT 1000'),
                default => null,
            };
            $one = new DatabaseExecutionPersistenceProvider($first);
            $two = new DatabaseExecutionPersistenceProvider($second);
            $one->save(ExecutionPersistenceContract::state('a0', overrides: ['searchAttributes' => ['customer' => 'ada']]));
            $one->save(ExecutionPersistenceContract::state('b0', overrides: ['searchAttributes' => ['customer' => 'ada']]));

            // Worker one holds its open checkpoint transaction while worker two saves an id in the same index gap.
            $first->beginTransaction();
            $one->save(ExecutionPersistenceContract::state('a1', overrides: ['searchAttributes' => ['customer' => 'grace', 'vip' => true]]));
            $two->save(ExecutionPersistenceContract::state('a2', overrides: ['searchAttributes' => ['customer' => 'alan', 'vip' => true]]));
            $first->commit();

            // A re-save drops an obsolete key, rewrites a kept one and adds a new one, under the same interleaving.
            $first->beginTransaction();
            $one->save(ExecutionPersistenceContract::state('a1', overrides: ['searchAttributes' => ['customer' => 'grace', 'region' => 'eu']]));
            $two->save(ExecutionPersistenceContract::state('a2', overrides: ['searchAttributes' => ['customer' => 'barbara']]));
            $first->commit();

            expect(ExecutionPersistenceContract::ids($one->findBySearchAttributes(['vip' => true])))->toBe([])
                ->and(ExecutionPersistenceContract::ids($one->findBySearchAttributes(['customer' => 'grace', 'region' => 'eu'])))->toBe(['a1'])
                ->and(ExecutionPersistenceContract::ids($two->findBySearchAttributes(['customer' => 'barbara'])))->toBe(['a2'])
                ->and($first->table(OrchestrationSchema::SEARCH_ATTRIBUTES)->whereIn('correlation_id', ['a1', 'a2'])->count())->toBe(3);
        } finally {
            if ($lane === 'sqlsrv') {
                $second->unprepared('SET LOCK_TIMEOUT -1'); // pooled SQL Server sessions keep session SETs past disconnect()
            }
            if ($first->transactionLevel() > 0) {
                $first->rollBack();
            }
            $first->disconnect();
            $second->disconnect();
        }
    })->group('integration')->skip(IntegrationDatabase::skipReason($lane) !== null, (string) IntegrationDatabase::skipReason($lane));
}
