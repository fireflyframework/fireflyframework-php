<?php

declare(strict_types=1);

use Firefly\FeatureFlags\Definition\Json;
use Firefly\FeatureFlags\Store\DatabaseFlagStore;
use Firefly\FeatureFlags\Store\FeatureFlagSchema;
use Firefly\FeatureFlags\Store\FlagStoreConflict;
use Firefly\FeatureFlags\Tests\Support\DatabaseStoreIntegration;
use Firefly\FeatureFlags\Tests\Support\FlagStores;
use Illuminate\Database\QueryException;
use Symfony\Component\Process\Process;

foreach (['Postgres' => ['pgsql', 'FIREFLY_PG_DSN'], 'Mysql' => ['mysql', 'FIREFLY_MYSQL_DSN'], 'MariaDB' => ['mysql', 'FIREFLY_MARIADB_DSN']] as $backend => [$driver, $variable]) {
    it("preserves exact keys and large canonical payloads on {$backend}", function () use ($driver, $variable): void {
        $connection = DatabaseStoreIntegration::connection($driver, $variable);
        FeatureFlagSchema::drop($connection->getSchemaBuilder());
        FeatureFlagSchema::create($connection->getSchemaBuilder());
        FeatureFlagSchema::create($connection->getSchemaBuilder());
        $store = new DatabaseFlagStore($connection, FlagStores::clock());
        $definition = ['state' => 'ENABLED', 'variants' => ['on' => str_repeat('é', 40000)], 'defaultVariant' => 'on'];
        $store->put('A', $definition, 'ada', 0);
        $store->put('a', $definition, 'bob', 0);
        expect(array_keys($store->all()))->toBe(['A', 'a'])
            ->and($store->get('a')?->updatedBy)->toBe('bob')
            ->and($store->history('A')[0]->actor)->toBe('ada')
            ->and($store->history('A')[0]->definition)->toBe($store->get('A')?->definition)
            ->and($connection->table(FeatureFlagSchema::FLAGS)->where('flag_key', 'A')->value('definition'))->toBe(Json::canonical($definition));
        expect(fn () => $store->put('A', $definition, 'eve', 0))->toThrow(FlagStoreConflict::class);
        expect($store->get('A')?->version)->toBe(1)->and(count($store->history('A')))->toBe(1);
        $store->delete('A', 'ada', 1);
        expect($store->get('A'))->toBeNull()->and($store->get('a')?->version)->toBe(1)
            ->and($store->history('A')[0]->previous)->toBe($store->get('a')?->definition);
        FeatureFlagSchema::drop($connection->getSchemaBuilder());
    })->group('integration')->skip(getenv($variable) === false, "Set {$variable} to an isolated server.");

    it("rolls back failed audit writes inside an outer transaction on {$backend}", function () use ($driver, $variable): void {
        $connection = DatabaseStoreIntegration::connection($driver, $variable);
        FeatureFlagSchema::drop($connection->getSchemaBuilder());
        FeatureFlagSchema::create($connection->getSchemaBuilder());
        $store = new DatabaseFlagStore($connection, FlagStores::clock());
        $definition = ['state' => 'ENABLED', 'variants' => ['on' => true], 'defaultVariant' => 'on'];
        $store->put('keep', $definition, 'ada');
        $connection->statement("ALTER TABLE firefly_feature_flag_changes ADD CONSTRAINT reject_eve CHECK (actor <> 'eve')");
        $connection->beginTransaction();
        foreach (['insert', 'update', 'delete'] as $operation) {
            expect(fn () => $operation === 'delete' ? $store->delete('keep', 'eve') : $store->put($operation === 'insert' ? 'new' : 'keep', $definition, 'eve'))->toThrow(QueryException::class);
            expect($connection->transactionLevel())->toBe(1);
        }
        $connection->commit();
        expect($store->get('new'))->toBeNull()->and($store->get('keep')?->version)->toBe(1)
            ->and(count($store->history('keep')))->toBe(1);
        FeatureFlagSchema::drop($connection->getSchemaBuilder());
    })->group('integration')->skip(getenv($variable) === false, "Set {$variable} to an isolated server.");
    it("serializes competing process updates and emits only surviving callbacks on {$backend}", function () use ($driver, $variable): void {
        $connection = DatabaseStoreIntegration::connection($driver, $variable);
        FeatureFlagSchema::drop($connection->getSchemaBuilder());
        FeatureFlagSchema::create($connection->getSchemaBuilder());
        $store = new DatabaseFlagStore($connection, FlagStores::clock());
        $definition = ['state' => 'ENABLED', 'variants' => ['on' => true], 'defaultVariant' => 'on'];
        $store->put('race', $definition, 'initial');
        $connection->beginTransaction();
        $store->put('race', $definition, 'parent', 1);
        $calls = [];
        $store->afterCommit(function () use (&$calls): void {
            $calls[] = 'parent';
        });
        $connection->beginTransaction();
        $connection->beginTransaction();
        $store->put('discarded', $definition, 'parent');
        $store->afterCommit(function () use (&$calls): void {
            $calls[] = 'discarded';
        });
        $connection->commit();
        $connection->rollBack();
        $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/Support/database-store-racer.php', $driver, $variable], timeout: 15);
        try {
            $process->start();
            expect($process->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'reading')))->toBeTrue()
                ->and($calls)->toBe([]);
            $connection->commit();
            expect($process->wait())->toBe(0, $process->getErrorOutput())
                ->and($process->getOutput())->toContain('conflict')
                ->and($store->get('race')?->version)->toBe(2)
                ->and($store->get('race')?->updatedBy)->toBe('parent')
                ->and(count($store->history('race')))->toBe(2)
                ->and($store->get('discarded'))->toBeNull()
                ->and($calls)->toBe(['parent']);
        } finally {
            $connection->rollBack(0);
            $process->stop();
            FeatureFlagSchema::drop($connection->getSchemaBuilder());
        }
    })->group('integration')->skip(getenv($variable) === false, "Set {$variable} to an isolated server.");

    it("reads PyFly microsecond UTC rows independently of the SQL session zone on {$backend}", function () use ($driver, $variable): void {
        $connection = DatabaseStoreIntegration::connection($driver, $variable);
        FeatureFlagSchema::drop($connection->getSchemaBuilder());
        FeatureFlagSchema::create($connection->getSchemaBuilder());
        $connection->statement($driver === 'pgsql' ? "SET TIME ZONE '-07:00'" : "SET time_zone = '-07:00'");
        $definition = '{"state": "ENABLED", "variants": {"on": {}}, "defaultVariant": "on"}';
        $connection->table(FeatureFlagSchema::FLAGS)->insert(['flag_key' => 'pyfly', 'definition' => $definition, 'version' => 3, 'updated_at' => '2026-10-01 12:00:00.123456', 'updated_by' => null]);
        $connection->table(FeatureFlagSchema::CHANGES)->insert(['flag_key' => 'pyfly', 'action' => 'put', 'definition' => $definition, 'previous' => null, 'actor' => null, 'changed_at' => '2026-10-01 12:00:00.123456']);
        $connection->statement($driver === 'pgsql' ? "SET TIME ZONE '+02:00'" : "SET time_zone = '+02:00'");
        $store = new DatabaseFlagStore($connection);
        expect($store->get('pyfly')?->updatedAt->format('Y-m-d H:i:s.uP'))->toBe('2026-10-01 12:00:00.123456+00:00')
            ->and($store->history('pyfly')[0]->changedAt->format('Y-m-d H:i:s.uP'))->toBe('2026-10-01 12:00:00.123456+00:00');
        FeatureFlagSchema::drop($connection->getSchemaBuilder());
    })->group('integration')->skip(getenv($variable) === false, "Set {$variable} to an isolated server.");

}
