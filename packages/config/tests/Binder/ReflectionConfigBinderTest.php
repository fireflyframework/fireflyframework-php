<?php

declare(strict_types=1);

use Firefly\Config\Binder\ReflectionConfigBinder;
use Firefly\Config\Tests\Fixtures\DatabaseProperties;
use Firefly\Config\Tests\Fixtures\MailProperties;
use Firefly\Config\Tests\Fixtures\OptionalProperties;
use Firefly\Kernel\Exception\Framework\ConfigurationException;

it('binds a flat config array onto a readonly DTO with coercion + defaults', function () {
    $mail = (new ReflectionConfigBinder)->bind(MailProperties::class, [
        'host' => 'smtp.example.com',
        'port' => '2525', // string coerced to int
    ]);

    expect($mail)->toBeInstanceOf(MailProperties::class)
        ->and($mail->host)->toBe('smtp.example.com')
        ->and($mail->port)->toBe(2525)
        ->and($mail->tls)->toBeFalse(); // default
});

it('binds a nested object-typed parameter recursively', function () {
    $db = (new ReflectionConfigBinder)->bind(DatabaseProperties::class, [
        'driver' => 'pgsql',
        'pool' => ['min' => 2, 'max' => 20],
        'replicas' => ['r1', 'r2'],
    ]);

    expect($db)->toBeInstanceOf(DatabaseProperties::class)
        ->and($db->driver)->toBe('pgsql')
        ->and($db->pool->min)->toBe(2)
        ->and($db->pool->max)->toBe(20)
        ->and($db->replicas)->toBe(['r1', 'r2']);
});

it('throws when a required property is missing', function () {
    (new ReflectionConfigBinder)->bind(MailProperties::class, ['port' => 25]);
})->throws(ConfigurationException::class);

it('binds an absent nullable parameter to null', function () {
    $optional = (new ReflectionConfigBinder)->bind(OptionalProperties::class, [
        'name' => 'x',
    ]);

    expect($optional)->toBeInstanceOf(OptionalProperties::class)
        ->and($optional->name)->toBe('x')
        ->and($optional->nickname)->toBeNull();
});

it('falls back to the constructor default when a property is present but explicitly null', function () {
    $mail = (new ReflectionConfigBinder)->bind(MailProperties::class, [
        'host' => 'h',
        'port' => null,
    ]);

    expect($mail->host)->toBe('h')
        ->and($mail->port)->toBe(25); // declared default, not null-coerced
});

it('falls back to the nested object\'s own defaults when its sub-array is empty', function () {
    $db = (new ReflectionConfigBinder)->bind(DatabaseProperties::class, [
        'driver' => 'pgsql',
        'pool' => [],
    ]);

    expect($db)->toBeInstanceOf(DatabaseProperties::class)
        ->and($db->driver)->toBe('pgsql')
        ->and($db->pool->min)->toBe(1)
        ->and($db->pool->max)->toBe(10)
        ->and($db->replicas)->toBe([]);
});
