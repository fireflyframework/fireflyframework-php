<?php

declare(strict_types=1);

use Firefly\Actuator\Endpoint\ExposureModel;
use Firefly\Actuator\Server\ManagementServerSettings;
use Firefly\Config\Config;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Illuminate\Config\Repository;

/**
 * @param  array<string, mixed>  $config
 */
function managementSettings(array $config): ManagementServerSettings
{
    return ManagementServerSettings::fromConfig(new Config(new Repository($config)));
}

it('defaults to no management port, no address and no prefix', function () {
    $settings = managementSettings([]);

    expect($settings->port)->toBeNull()
        ->and($settings->address)->toBeNull()
        ->and($settings->basePath)->toBe('')
        ->and($settings->isSeparate())->toBeFalse();
});

it('reads port, address and base path', function () {
    $settings = managementSettings(['firefly' => ['management' => ['server' => [
        'port' => 9001,
        'address' => ' 127.0.0.1 ',
        'base-path' => '/manage/',
    ]]]]);

    expect($settings->port)->toBe(9001)
        ->and($settings->address)->toBe('127.0.0.1')
        ->and($settings->basePath)->toBe('manage')
        ->and($settings->isSeparate())->toBeTrue();
});

// `'port' => env('FIREFLY_MANAGEMENT_PORT')` is what a published config file actually contains, and env() answers
// null (or '' for a set-but-empty variable) when the variable is absent — while Repository::has() reports TRUE for
// a key explicitly set to null. Reading this through Config::int() would throw "Required configuration key is not
// set" for the most ordinary config file there is.
it('treats an explicit null or empty port as unset, not as an error', function (mixed $raw) {
    expect(managementSettings(['firefly' => ['management' => ['server' => ['port' => $raw]]]])->port)->toBeNull();
})->with([[null], ['']]);

it('accepts a numeric string port, because .env values are strings', function () {
    expect(managementSettings(['firefly' => ['management' => ['server' => ['port' => '9001']]]])->port)->toBe(9001);
});

it('rejects a port that is not a TCP port', function (mixed $raw) {
    expect(fn () => managementSettings(['firefly' => ['management' => ['server' => ['port' => $raw]]]]))
        ->toThrow(ConfigurationException::class, 'must be a TCP port between 1 and 65535');
})->with([[0], [70000], [-1], ['nine thousand'], [true]]);

it('prefixes the exposure base path with the management server base path', function () {
    $exposure = ExposureModel::fromConfig(new Config(new Repository([])));

    expect(managementSettings([])->mountPath($exposure))->toBe('actuator')
        ->and(managementSettings(['firefly' => ['management' => ['server' => ['base-path' => '/manage']]]])->mountPath($exposure))
        ->toBe('manage/actuator');
});

// DELIBERATE DIVERGENCE FROM SPRING: Spring applies management.server.base-path only when the management port
// differs. Applying it unconditionally keeps the actuator's URL identical in development (no management port) and
// production (management port set) from one config file.
it('applies the base path prefix even without a management port', function () {
    $exposure = ExposureModel::fromConfig(new Config(new Repository([])));
    $settings = managementSettings(['firefly' => ['management' => ['server' => ['base-path' => 'manage']]]]);

    expect($settings->isSeparate())->toBeFalse()->and($settings->mountPath($exposure))->toBe('manage/actuator');
});

it('rejects a management port equal to the application port', function () {
    expect(fn () => managementSettings([])->assertDistinctFrom(null))->not->toThrow(ConfigurationException::class);

    $settings = managementSettings(['firefly' => ['management' => ['server' => ['port' => 8000]]]]);

    expect(fn () => $settings->assertDistinctFrom(8000))
        ->toThrow(ConfigurationException::class, 'is the application port');
    expect(fn () => $settings->assertDistinctFrom(9001))->not->toThrow(ConfigurationException::class);
    expect(fn () => $settings->assertDistinctFrom(null))->not->toThrow(ConfigurationException::class);
});

it('resolves the application port from firefly.server.port first', function () {
    $config = new Config(new Repository([
        'firefly' => ['server' => ['port' => '8000']],
        'app' => ['url' => 'http://localhost:1234'],
    ]));

    expect(ManagementServerSettings::applicationPort($config))->toBe(8000);
});

it('falls back to an explicit port in app.url', function () {
    $config = new Config(new Repository(['app' => ['url' => 'http://localhost:8000']]));

    expect(ManagementServerSettings::applicationPort($config))->toBe(8000);
});

// Refusing to guess is the point: a public URL behind a proxy says nothing about the port this process's listener
// accepted on, and an invented 443/80 would abort correctly-configured boots.
it('returns null rather than guessing a default port for a portless app.url', function () {
    $config = new Config(new Repository(['app' => ['url' => 'https://api.example.test']]));

    expect(ManagementServerSettings::applicationPort($config))->toBeNull();
});

it('returns null when nothing declares an application port', function () {
    expect(ManagementServerSettings::applicationPort(new Config(new Repository([]))))->toBeNull();
});
