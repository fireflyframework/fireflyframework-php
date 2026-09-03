<?php

declare(strict_types=1);

use Firefly\Admin\AdminSettings;
use Firefly\Config\Config;
use Illuminate\Config\Repository;

/** @param array<string,mixed> $values */
function adminConfig(array $values): Config
{
    return new Config(new Repository($values));
}

it('follows app.debug when firefly.admin.enabled is unset', function (bool $debug) {
    expect(AdminSettings::fromConfig(adminConfig(['app' => ['debug' => $debug]]))->enabled)->toBe($debug);
})->with([[true], [false]]);

// The dashboard reads endpoints in-process, bypassing ExposureModel, so its URL is the only boundary. An
// explicit setting must win in BOTH directions — including turning it ON in a non-debug environment that
// puts the route behind its own auth middleware.
it('lets an explicit setting override the debug default in both directions', function () {
    expect(AdminSettings::fromConfig(adminConfig([
        'app' => ['debug' => true],
        'firefly' => ['admin' => ['enabled' => false]],
    ]))->enabled)->toBeFalse();

    expect(AdminSettings::fromConfig(adminConfig([
        'app' => ['debug' => false],
        'firefly' => ['admin' => ['enabled' => true]],
    ]))->enabled)->toBeTrue();
});

it('defaults to a /firefly base path and normalises slashes', function (string $configured, string $expected) {
    $settings = AdminSettings::fromConfig(adminConfig(['firefly' => ['admin' => ['base-path' => $configured]]]));

    expect($settings->basePath)->toBe($expected)
        ->and($settings->url())->toBe('/'.$expected)
        ->and($settings->url('beans'))->toBe('/'.$expected.'/beans');
})->with([
    ['/firefly', 'firefly'],
    ['firefly/', 'firefly'],
    ['/admin/ops/', 'admin/ops'],
    ['/', 'firefly'],
]);

it('titles itself after the application', function () {
    expect(AdminSettings::fromConfig(adminConfig(['app' => ['name' => 'Lumen']]))->title)->toBe('Lumen');
});
