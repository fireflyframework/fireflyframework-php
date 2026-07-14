<?php

declare(strict_types=1);

use Firefly\Config\Binder\ReflectionConfigBinder;
use Firefly\Config\Tests\Fixtures\DatabaseProperties;
use Firefly\Config\Tests\Fixtures\MailProperties;
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
