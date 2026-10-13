<?php

declare(strict_types=1);

use Firefly\Scheduling\Database\SchedulingDatabaseBootServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\MigrationServiceProvider;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;

/**
 * Installing firefly/scheduling-database is inert until firefly.scheduling.lock.provider=database: only then does
 * `php artisan migrate` pick up the firefly_scheduling_locks table. Every other provider keeps its schema untouched.
 */
it('loads the lease-table migration only under provider=database', function (?string $provider, bool $loaded) {
    $app = new Application;
    $app->instance('config', new Repository(['firefly' => ['scheduling' => $provider === null ? [] : ['lock' => ['provider' => $provider]]]]));
    $app->instance('files', new Filesystem);
    $app->register(DatabaseServiceProvider::class);
    $app->register(MigrationServiceProvider::class);
    (new SchedulingDatabaseBootServiceProvider($app))->boot();
    /** @var Migrator $migrator */
    $migrator = $app->make('migrator');

    $paths = [];
    foreach ($migrator->paths() as $path) {
        $paths[] = realpath($path);
    }

    expect($paths)->toBe($loaded ? [realpath(dirname(__DIR__).'/database/migrations')] : []);
    $app->flush();
})->with([
    'unset' => [null, false],
    'cache' => ['cache', false],
    'postgres' => ['postgres', false],
    'database' => ['database', true],
]);
