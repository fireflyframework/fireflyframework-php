<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Security\Tests\Fixtures\Users\Account;
use Firefly\Security\User\UserStoreSettings;
use Illuminate\Config\Repository;

/** @param array<string, mixed> $users */
function userStore(array $users): UserStoreSettings
{
    return UserStoreSettings::fromConfig(new Config(new Repository(['firefly' => ['security' => ['users' => $users]]])));
}

it('keeps the memory driver\'s shape: the map IS the accounts, driver optional', function () {
    $settings = userStore(['alice' => ['password' => '{noop}x', 'authorities' => ['ROLE_ADMIN']]]);

    expect($settings->driver)->toBe('memory')
        ->and($settings->accounts)->toBe(['alice' => ['password' => '{noop}x', 'authorities' => ['ROLE_ADMIN']]])
        ->and(userStore([])->accounts)->toBe([])
        ->and(UserStoreSettings::fromConfig(new Config(new Repository([])))->driver)->toBe('memory');
});

it('reads the eloquent driver with its documented defaults, and strips the reserved keys from the accounts', function () {
    $settings = userStore(['driver' => 'eloquent', 'model' => Account::class, 'locked_column' => 'locked']);

    expect($settings->driver)->toBe('eloquent')
        ->and($settings->model)->toBe(Account::class)
        ->and($settings->usernameColumn)->toBe('email')
        ->and($settings->passwordColumn)->toBe('password')
        ->and($settings->enabledColumn)->toBe('')
        ->and($settings->lockedColumn)->toBe('locked')
        ->and($settings->authorities)->toBe('authorities')
        ->and($settings->accounts)->toBe([]);
});

it('refuses an unknown driver, an eloquent driver without a model class, a class that does not exist and a class that is not an Eloquent model', function () {
    expect(fn () => userStore(['driver' => 'ldap']))->toThrow(ConfigurationException::class, 'driver')
        ->and(fn () => userStore(['driver' => 'eloquent']))->toThrow(ConfigurationException::class, 'model')
        ->and(fn () => userStore(['driver' => 'eloquent', 'model' => 'App\\Models\\Nope']))->toThrow(ConfigurationException::class, 'App\\Models\\Nope')
        // A class that exists but is no model would otherwise boot cleanly and be refused on the first login
        // attempt — the 500 the boot-time resolution exists to prevent — so fromConfig() asks is_a() too.
        ->and(fn () => userStore(['driver' => 'eloquent', 'model' => stdClass::class]))->toThrow(ConfigurationException::class, 'Eloquent')
        ->and(fn () => userStore(['driver' => 'eloquent', 'model' => UserStoreSettings::class]))->toThrow(ConfigurationException::class, 'not an Eloquent model');
});
