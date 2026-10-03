<?php

declare(strict_types=1);

use Firefly\FeatureFlags\FeatureFlagsWiringProvider;
use Illuminate\Config\Repository;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Database\MigrationServiceProvider;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;

it('loads the migration only for an enabled database store', function (bool $enabled, bool $storeEnabled, string $driver, bool $expected): void {
    $app = new Application;
    $app->instance('config', new Repository(['firefly' => ['feature-flags' => ['enabled' => $enabled, 'sources' => ['store' => ['enabled' => $storeEnabled, 'driver' => $driver]]]]]));
    $app->instance('files', new Filesystem);
    $app->register(DatabaseServiceProvider::class);
    $app->register(MigrationServiceProvider::class);
    (new FeatureFlagsWiringProvider($app))->boot();

    $paths = $app->make('migrator')->paths();
    expect(in_array(dirname(__DIR__, 2).'/database/migrations', $paths, true))->toBe($expected);
    $app->flush();
})->with([
    'enabled database store' => [true, true, 'database', true],
    'memory store' => [true, true, 'memory', false],
    'store disabled' => [true, false, 'database', false],
    'feature flags disabled' => [false, true, 'database', false],
]);
