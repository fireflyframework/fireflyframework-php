<?php

declare(strict_types=1);

use Firefly\Config\Binder\ReflectionConfigBinder;
use Firefly\Config\Tests\Fixtures\AcronymProperties;
use Firefly\Config\Tests\Fixtures\DatabaseProperties;
use Firefly\Config\Tests\Fixtures\MailProperties;
use Firefly\Config\Tests\Fixtures\OptionalProperties;
use Firefly\Config\Tests\Fixtures\WalletProperties;
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

/**
 * RELAXED BINDING (Spring Boot parity).
 *
 * The binder used to match a constructor parameter against ONE spelling: its exact PHP name. Every
 * other spelling a Laravel config file might reasonably use — snake_case, kebab-case, the
 * SCREAMING_SNAKE of an env var pasted straight into an array — bound nothing at all and silently
 * yielded the constructor default, which is the worst possible failure mode for configuration:
 * no exception, no log line, just a wrong number in production.
 */
it('binds a snake_case config key onto a camelCase constructor parameter', function () {
    $wallet = (new ReflectionConfigBinder)->bind(WalletProperties::class, [
        'daily_transfer_limit_minor' => 250_000,
        'default_currency' => 'USD',
    ]);

    expect($wallet->dailyTransferLimitMinor)->toBe(250_000)
        ->and($wallet->defaultCurrency)->toBe('USD');
});

it('binds kebab-case and SCREAMING_SNAKE config keys onto camelCase constructor parameters', function () {
    $kebab = (new ReflectionConfigBinder)->bind(WalletProperties::class, [
        'daily-transfer-limit-minor' => 111,
        'default-currency' => 'GBP',
    ]);
    $upper = (new ReflectionConfigBinder)->bind(WalletProperties::class, [
        'DAILY_TRANSFER_LIMIT_MINOR' => 222,
        'DEFAULT_CURRENCY' => 'CHF',
    ]);

    expect($kebab->dailyTransferLimitMinor)->toBe(111)
        ->and($kebab->defaultCurrency)->toBe('GBP')
        ->and($upper->dailyTransferLimitMinor)->toBe(222)
        ->and($upper->defaultCurrency)->toBe('CHF');
});

it('prefers the exact parameter name over every relaxed spelling, in a fixed precedence order', function () {
    $binder = new ReflectionConfigBinder;

    // All four spellings present at once: exact wins, then snake, then kebab, then UPPER.
    expect($binder->bind(WalletProperties::class, [
        'dailyTransferLimitMinor' => 1,
        'daily_transfer_limit_minor' => 2,
        'daily-transfer-limit-minor' => 3,
        'DAILY_TRANSFER_LIMIT_MINOR' => 4,
    ])->dailyTransferLimitMinor)->toBe(1);

    expect($binder->bind(WalletProperties::class, [
        'daily_transfer_limit_minor' => 2,
        'daily-transfer-limit-minor' => 3,
        'DAILY_TRANSFER_LIMIT_MINOR' => 4,
    ])->dailyTransferLimitMinor)->toBe(2);

    expect($binder->bind(WalletProperties::class, [
        'daily-transfer-limit-minor' => 3,
        'DAILY_TRANSFER_LIMIT_MINOR' => 4,
    ])->dailyTransferLimitMinor)->toBe(3);

    expect($binder->bind(WalletProperties::class, [
        'DAILY_TRANSFER_LIMIT_MINOR' => 4,
    ])->dailyTransferLimitMinor)->toBe(4);
});

it('keeps searching the relaxed spellings when a higher-precedence key is present but null', function () {
    // A present-but-null key has ALWAYS meant "not supplied" here (it is what the Laravel idiom
    // 'key' => env('KEY') yields when the variable is unset), so it must not mask a real value
    // written under another spelling — it only ever falls through to the constructor default.
    $wallet = (new ReflectionConfigBinder)->bind(WalletProperties::class, [
        'dailyTransferLimitMinor' => null,
        'daily_transfer_limit_minor' => 777,
    ]);

    expect($wallet->dailyTransferLimitMinor)->toBe(777);
});

it('relaxes acronyms to the spelling a human would actually write', function () {
    $props = (new ReflectionConfigBinder)->bind(AcronymProperties::class, [
        'api_url' => 'https://api.example.com',
        'http_proxy_host' => 'proxy.internal',
    ]);

    expect($props->apiURL)->toBe('https://api.example.com')
        ->and($props->HTTPProxyHost)->toBe('proxy.internal');
});

it('names every spelling it tried when a required property is missing', function () {
    expect(fn () => (new ReflectionConfigBinder)->bind(MailProperties::class, ['port' => 25]))
        ->toThrow(ConfigurationException::class, 'host, HOST');
});
