<?php

declare(strict_types=1);

use Firefly\Actuator\Health\DbHealthIndicator;
use Firefly\Actuator\Health\DiskSpaceHealthIndicator;
use Firefly\Actuator\Health\PingHealthIndicator;
use Firefly\Actuator\Health\Status;
use Firefly\Config\Config;
use Illuminate\Config\Repository;
use Illuminate\Database\Capsule\Manager as Capsule;

it('ping is always UP', function () {
    expect((new PingHealthIndicator)->health()->status)->toBe(Status::Up);
});

it('disk space is UP when free space exceeds the threshold', function () {
    $config = new Config(new Repository(['firefly' => ['management' => ['endpoint' => ['health' => ['diskspace' => ['threshold' => 1]]]]]]));
    $health = (new DiskSpaceHealthIndicator($config))->health();

    expect($health->status)->toBe(Status::Up)
        ->and($health->details)->toHaveKeys(['total', 'free', 'threshold']);
});

it('disk space is DOWN when free space is below an absurd threshold', function () {
    $config = new Config(new Repository(['firefly' => ['management' => ['endpoint' => ['health' => ['diskspace' => ['threshold' => PHP_INT_MAX]]]]]]));

    expect((new DiskSpaceHealthIndicator($config))->health()->status)->toBe(Status::Down);
});

it('db is UP against a working sqlite connection and DOWN when the query throws', function () {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);

    $up = new DbHealthIndicator($capsule->getDatabaseManager());
    expect($up->health()->status)->toBe(Status::Up);

    $broken = new Capsule;
    $broken->addConnection(['driver' => 'sqlite', 'database' => '/nonexistent/dir/db.sqlite', 'prefix' => '']);
    expect((new DbHealthIndicator($broken->getDatabaseManager()))->health()->status)->toBe(Status::Down);
});
