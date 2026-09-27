<?php

declare(strict_types=1);

use Firefly\Eda\Postgres\EdaPostgresBootServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\MigrationServiceProvider;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;

it('only registers outbox migrations when the PostgreSQL transport is selected', function (string $provider, bool $expected) {
    $app = new Application;
    $app->instance('config', new Repository(['firefly' => ['eda' => ['provider' => $provider]]]));
    $app->instance('files', new Filesystem);
    $app->register(DatabaseServiceProvider::class);
    $app->register(MigrationServiceProvider::class);
    (new EdaPostgresBootServiceProvider($app))->boot();
    /** @var Migrator $migrator */
    $migrator = $app->make('migrator');
    expect($migrator->paths() !== [])->toBe($expected);
    $app->flush();
})->with(['default' => ['sync', false], 'Kafka' => ['kafka', false], 'Postgres' => ['postgres', true]]);
