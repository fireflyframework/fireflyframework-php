<?php

declare(strict_types=1);

use Firefly\Actuator\Health\ConditionalHealthIndicator;
use Firefly\Actuator\Health\DbHealthIndicator;
use Firefly\Actuator\Health\DiskSpaceHealthIndicator;
use Firefly\Actuator\Health\PingHealthIndicator;
use Firefly\Actuator\Health\Status;
use Firefly\Config\Config;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
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

    $up = new DbHealthIndicator($capsule->getDatabaseManager(), new Config(new Repository(['database' => ['default' => 'x', 'connections' => ['x' => ['driver' => 'sqlite']]]])));
    expect($up->health()->status)->toBe(Status::Up);

    $broken = new Capsule;
    $broken->addConnection(['driver' => 'sqlite', 'database' => '/nonexistent/dir/db.sqlite', 'prefix' => '']);
    expect((new DbHealthIndicator($broken->getDatabaseManager(), new Config(new Repository(['database' => ['default' => 'x', 'connections' => ['x' => ['driver' => 'sqlite']]]]))))->health()->status)->toBe(Status::Down);
});

it('is available only when database.default names a connection with a driver', function () {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $manager = $capsule->getDatabaseManager();

    $configured = new DbHealthIndicator($manager, new Config(new Repository(['database' => ['default' => 'main', 'connections' => ['main' => ['driver' => 'sqlite', 'database' => ':memory:']]]])));
    $noDefault = new DbHealthIndicator($manager, new Config(new Repository(['database' => ['default' => null, 'connections' => ['main' => ['driver' => 'sqlite']]]])));
    $emptyDefault = new DbHealthIndicator($manager, new Config(new Repository(['database' => ['default' => '', 'connections' => []]])));
    $unknownConnection = new DbHealthIndicator($manager, new Config(new Repository(['database' => ['default' => 'main', 'connections' => []]])));
    $noDriver = new DbHealthIndicator($manager, new Config(new Repository(['database' => ['default' => 'main', 'connections' => ['main' => ['database' => ':memory:']]]])));

    expect($configured)->toBeInstanceOf(ConditionalHealthIndicator::class)
        ->and($configured->available())->toBeTrue()
        ->and($noDefault->available())->toBeFalse()
        ->and($emptyDefault->available())->toBeFalse()
        ->and($unknownConnection->available())->toBeFalse()
        ->and($noDriver->available())->toBeFalse();
});

it('is enabled unless the key says false, like Spring\'s @ConditionalOnEnabledHealthIndicator', function () {
    $attributes = (new ReflectionClass(DbHealthIndicator::class))->getAttributes(ConditionalOnProperty::class);
    $condition = $attributes[0]->newInstance();

    expect($condition->name)->toBe('firefly.management.endpoint.health.db.enabled')
        ->and($condition->havingValue)->toBe('true')
        ->and($condition->matchIfMissing)->toBeTrue();
});
