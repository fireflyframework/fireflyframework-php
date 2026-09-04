<?php

declare(strict_types=1);

use Firefly\Config\Profile\ProfileResolver;
use Orchestra\Testbench\TestCase;

uses(TestCase::class);

/**
 * The REGRESSION TEST for the defect that motivated ProfileResolver's rewrite.
 *
 * ProfileResolver used to read raw getenv('APP_ENV'). Under orchestra/testbench there IS no
 * APP_ENV in the process environment: testbench never calls putenv(), never populates $_ENV or
 * $_SERVER, and never loads a .env file — it builds the application and sets the environment on the
 * config repository directly. getenv() therefore returned false, the resolver fell through to its
 * last branch, and every profile-gated bean in every LaraFly test suite in the world silently saw
 * the profile set ['default'] instead of ['testing']. Which is to say: profiles collapsed to a
 * single meaningless value in exactly the situation where a developer is trying to prove that
 * profile gating works.
 *
 * The `php artisan config:cache` case is the same bug wearing production clothes and is covered in
 * ProfileResolverTest: Laravel's LoadEnvironmentVariables bootstrapper returns early when the
 * config is cached, so .env is never parsed and getenv() sees nothing — while the cached config
 * repository holds the correct app.env all along.
 */
it('reads the active profile from the Laravel config repository under testbench, where getenv() sees nothing', function () {
    // The precondition that broke the old implementation, asserted rather than assumed: if a future
    // testbench release starts exporting APP_ENV, this line fails loudly and tells the next reader
    // that the regression this file guards has changed shape.
    expect(getenv('APP_ENV'))->toBeFalse();

    // Read the environment back off the repository rather than hard-coding it: testbench's default
    // differs between a bare TestCase and a workbench skeleton, and the point of this test is that
    // the resolver AGREES WITH LARAVEL, not that Laravel says any particular word.
    $environment = config('app.env');

    expect($environment)->toBeString()->not->toBe('')
        ->and((new ProfileResolver)->resolve()->all())->toBe([$environment])
        // The old getenv()-only resolver produced exactly this, for every testbench suite:
        ->and((new ProfileResolver)->resolve()->all())->not->toBe(['default']);
});

it('prefers FIREFLY_PROFILES_ACTIVE from the config repository over the Laravel environment', function () {
    config(['firefly.profiles.active' => ['prod', 'eu']]);

    expect((new ProfileResolver)->resolve()->all())->toBe(['prod', 'eu']);
});
