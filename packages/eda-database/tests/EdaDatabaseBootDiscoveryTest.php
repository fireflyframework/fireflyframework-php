<?php

declare(strict_types=1);

use Firefly\Eda\Database\EdaDatabaseBootServiceProvider;
use Firefly\Eda\Postgres\EdaPostgresBootServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\MigrationServiceProvider;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;

it('loads the one set of outbox migrations under either outbox provider, and none otherwise', function (string $provider, bool $loaded) {
    $app = new Application;
    $app->instance('config', new Repository(['firefly' => ['eda' => ['provider' => $provider]]]));
    $app->instance('files', new Filesystem);
    $app->register(DatabaseServiceProvider::class);
    $app->register(MigrationServiceProvider::class);
    (new EdaDatabaseBootServiceProvider($app))->boot();
    (new EdaPostgresBootServiceProvider($app))->boot();
    /** @var Migrator $migrator */
    $migrator = $app->make('migrator');

    $paths = [];
    foreach ($migrator->paths() as $path) {
        $paths[] = realpath($path);
    }

    expect($paths)->toBe($loaded ? [realpath(dirname(__DIR__).'/database/migrations')] : []);
    $app->flush();
})->with([
    'memory' => ['memory', false],
    'database' => ['database', true],
    'postgres' => ['postgres', true],
]);
