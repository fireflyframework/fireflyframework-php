<?php

declare(strict_types=1);

use Firefly\FeatureFlags\Store\FeatureFlagSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;

uses(TestCase::class);

it('creates and drops the two tables on the configured connection', function (): void {
    config(['database.default' => 'testing', 'database.connections.testing' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_10_01_000000_create_firefly_feature_flags_tables.php';

    if (! $migration instanceof Migration || ! method_exists($migration, 'up') || ! method_exists($migration, 'down')) {
        throw new RuntimeException('Expected an executable migration.');
    }
    $migration->up();
    $created = [Schema::hasTable(FeatureFlagSchema::FLAGS), Schema::hasTable(FeatureFlagSchema::CHANGES)];
    $migration->down();

    expect($created)->toBe([true, true])
        ->and(Schema::hasTable(FeatureFlagSchema::FLAGS))->toBeFalse()
        ->and($migration->getConnection())->toBeNull();
});

it('migrates only the explicitly configured store connection', function (): void {
    config([
        'database.default' => 'testing',
        'database.connections.testing' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'database.connections.flags' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'firefly.feature-flags.sources.store.connection' => 'flags',
    ]);
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_10_01_000000_create_firefly_feature_flags_tables.php';
    if (! $migration instanceof Migration || ! method_exists($migration, 'up') || ! method_exists($migration, 'down')) {
        throw new RuntimeException('Expected an executable migration.');
    }
    $migration->up();
    $migration->up();
    expect($migration->getConnection())->toBe('flags')
        ->and(Schema::connection('flags')->hasTable(FeatureFlagSchema::FLAGS))->toBeTrue()
        ->and(Schema::connection('testing')->hasTable(FeatureFlagSchema::FLAGS))->toBeFalse();
    $migration->down();
    expect(Schema::connection('flags')->hasTable(FeatureFlagSchema::FLAGS))->toBeFalse()
        ->and(Schema::connection('flags')->hasTable(FeatureFlagSchema::CHANGES))->toBeFalse();
});
