<?php

declare(strict_types=1);

use Firefly\FeatureFlags\Tests\Support\SyncServerTestCase;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\ServiceProvider;

uses(SyncServerTestCase::class);

it('publishes the migration under its tag even while the database store is off', function (): void {
    /** @var SyncServerTestCase $this */
    $paths = ServiceProvider::pathsToPublish(null, 'firefly-feature-flags-migrations');
    /** @var Migrator $migrator */
    $migrator = $this->app()->make('migrator');

    expect(array_keys($paths))->toHaveCount(1)
        ->and((string) array_key_first($paths))->toEndWith('2026_10_01_000000_create_firefly_feature_flags_tables.php')
        ->and(array_filter($migrator->paths(), static fn (string $path): bool => str_contains($path, 'feature-flags')))->toBe([]);
});
