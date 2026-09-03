<?php

declare(strict_types=1);

use Firefly\Config\Profile\ProfileResolver;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;

// Several tests below swap the globally shared container to prove the resolver's fallback to a
// bound 'config' repository. Capture and RESTORE whatever was installed rather than nulling it: the
// package suites share one PHP process, and a testbench-booted application left in place by an
// earlier file must still be there for a later one.
$previousContainer = null;

beforeEach(function () use (&$previousContainer) {
    $previousContainer = Container::getInstance();
});

afterEach(function () use (&$previousContainer) {
    putenv('FIREFLY_PROFILES_ACTIVE');
    putenv('APP_ENV');
    unset(
        $_ENV['FIREFLY_PROFILES_ACTIVE'],
        $_ENV['APP_ENV'],
        $_SERVER['FIREFLY_PROFILES_ACTIVE'],
        $_SERVER['APP_ENV'],
    );

    Container::setInstance($previousContainer instanceof Container ? $previousContainer : null);
});

it('parses FIREFLY_PROFILES_ACTIVE as a comma-separated set, trimming blanks', function () {
    putenv('FIREFLY_PROFILES_ACTIVE=prod, eu ,,batch');

    expect((new ProfileResolver)->resolve()->all())->toBe(['prod', 'eu', 'batch']);
});

it('falls back to APP_ENV when no explicit profiles are set', function () {
    putenv('APP_ENV=staging');

    expect((new ProfileResolver)->resolve()->all())->toBe(['staging']);
});

it('defaults to [default] when nothing is set', function () {
    expect((new ProfileResolver)->resolve()->all())->toBe(['default']);
});

/**
 * `php artisan config:cache` is the production half of the defect the testbench test covers.
 * Laravel's LoadEnvironmentVariables bootstrapper returns EARLY when the configuration is cached,
 * so the .env file is never parsed: getenv('APP_ENV') is false in a cached production boot even
 * though the cached repository holds the right value under app.env. The old resolver therefore
 * reported ['default'] on every cached production process — profiles silently disabled themselves
 * the moment an application did the one thing every deployment guide tells it to do.
 */
it('reads APP_ENV from the config repository when the environment is empty (php artisan config:cache)', function () {
    $repository = new Repository(['app' => ['env' => 'production']]);

    expect(getenv('APP_ENV'))->toBeFalse()
        ->and((new ProfileResolver($repository))->resolve()->all())->toBe(['production']);
});

it('reads firefly.profiles.active from the config repository, as a list or as a comma-separated string', function () {
    expect((new ProfileResolver(new Repository(['firefly' => ['profiles' => ['active' => ['prod', 'eu']]]])))->resolve()->all())
        ->toBe(['prod', 'eu'])
        ->and((new ProfileResolver(new Repository(['firefly' => ['profiles' => ['active' => 'prod, eu']]])))->resolve()->all())
        ->toBe(['prod', 'eu']);
});

/**
 * PHPUnit's <env> entries, Docker's `--env`, php-fpm's env[] and testbench all populate $_ENV or
 * $_SERVER without ever calling putenv(), so getenv() cannot see them. Laravel's own
 * Illuminate\Support\Env reads all three, which is exactly why the resolver goes through it.
 */
it('reads an APP_ENV that lives only in $_ENV, where getenv() is blind', function () {
    $_ENV['APP_ENV'] = 'qa';

    expect(getenv('APP_ENV'))->toBeFalse()
        ->and((new ProfileResolver)->resolve()->all())->toBe(['qa']);
});

it('lets a real environment variable override the config repository', function () {
    putenv('FIREFLY_PROFILES_ACTIVE=canary');

    $repository = new Repository(['firefly' => ['profiles' => ['active' => ['prod']]], 'app' => ['env' => 'production']]);

    expect((new ProfileResolver($repository))->resolve()->all())->toBe(['canary']);
});

it('falls back to the container-bound config repository when none is injected', function () {
    // The zero-argument `new ProfileResolver` call sites that already exist across the framework
    // (FireflyAutoConfigureServiceProvider is one) must keep working AND must keep seeing a cached
    // configuration, so the resolver reaches for the application's bound 'config' repository when
    // no repository was handed to it.
    $container = new Container;
    $container->instance('config', new Repository(['app' => ['env' => 'production']]));
    Container::setInstance($container);

    expect((new ProfileResolver)->resolve()->all())->toBe(['production']);
});

it('ignores a container with no config binding rather than exploding', function () {
    Container::setInstance(new Container);

    expect((new ProfileResolver)->resolve()->all())->toBe(['default']);
});

it('ignores a blank or non-scalar configured value and keeps looking', function () {
    $repository = new Repository([
        'firefly' => ['profiles' => ['active' => '   ']],
        'app' => ['env' => 'production'],
    ]);

    expect((new ProfileResolver($repository))->resolve()->all())->toBe(['production']);
});
