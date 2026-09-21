<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Security\Session\SessionSecuritySettings;
use Illuminate\Config\Repository;

/** @param array<string, mixed> $security */
function sessionSettings(array $security): SessionSecuritySettings
{
    return new SessionSecuritySettings(new Config(new Repository(['firefly' => ['security' => $security]])));
}

it('is off by default and on when any session-backed mechanism is on', function () {
    expect(sessionSettings([])->enabled())->toBeFalse()
        ->and(sessionSettings(['session' => ['enabled' => true]])->enabled())->toBeTrue()
        ->and(sessionSettings(['form_login' => ['enabled' => true]])->enabled())->toBeTrue()
        ->and(sessionSettings(['remember_me' => ['enabled' => true]])->enabled())->toBeTrue()
        ->and(sessionSettings(['oauth2' => ['client' => ['login' => ['enabled' => true]]]])->enabled())->toBeTrue()
        ->and(sessionSettings(['http_basic' => ['enabled' => true]])->enabled())->toBeFalse()
        ->and(sessionSettings(['http_basic' => ['enabled' => true, 'session' => true]])->enabled())->toBeTrue()
        ->and(sessionSettings([])->fixationProtection())->toBeTrue()
        ->and(sessionSettings(['session' => ['fixation_protection' => false]])->fixationProtection())->toBeFalse();
});

it('reads the flags live, so a test can flip them after boot', function () {
    $repository = new Repository(['firefly' => ['security' => []]]);
    $settings = new SessionSecuritySettings(new Config($repository));

    expect($settings->enabled())->toBeFalse();

    $repository->set('firefly.security.form_login.enabled', true);

    expect($settings->enabled())->toBeTrue();
});
