<?php

declare(strict_types=1);

use Firefly\Config\Profile\ProfileResolver;

afterEach(function () {
    putenv('FIREFLY_PROFILES_ACTIVE');
    putenv('APP_ENV');
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
