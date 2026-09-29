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

it('floors the refresh interval so the page cannot reload faster than it renders', function (int $configured, int $expected) {
    $settings = AdminSettings::fromConfig(adminConfig(['firefly' => ['admin' => ['refresh-seconds' => $configured]]]));

    expect($settings->refreshSeconds)->toBe($expected);
})->with([[30, 30], [1, 2], [0, 2], [-5, 2]]);

it('falls back to following the operating system for an unrecognised theme', function (string $configured, string $expected) {
    expect(AdminSettings::fromConfig(adminConfig(['firefly' => ['admin' => ['theme' => $configured]]]))->theme)->toBe($expected);
})->with([['dark', 'dark'], ['LIGHT', 'light'], ['auto', 'auto'], ['solarized', 'auto'], ['', 'auto']]);

it('defaults the graph cap and never lets it go negative', function () {
    expect(AdminSettings::fromConfig(adminConfig([]))->graphMaxNodes)->toBe(220)
        ->and(AdminSettings::fromConfig(adminConfig(['firefly' => ['admin' => ['graph' => ['max-nodes' => 40]]]]))->graphMaxNodes)->toBe(40)
        ->and(AdminSettings::fromConfig(adminConfig(['firefly' => ['admin' => ['graph' => ['max-nodes' => -9]]]]))->graphMaxNodes)->toBe(0);
});

// Excluding a page is a refusal, not a menu preference: hiding `env` from the menu achieves nothing if the
// URL still answers.
it('refuses an excluded page as well as hiding it', function () {
    $settings = AdminSettings::fromConfig(adminConfig(['firefly' => ['admin' => ['pages' => ['exclude' => 'env, Caches']]]]));

    expect($settings->excludedPages)->toBe(['env', 'caches'])
        ->and($settings->allows('env'))->toBeFalse()
        ->and($settings->allows('caches'))->toBeFalse()
        ->and($settings->allows('beans'))->toBeTrue();
});

it('lets the overview itself be excluded, under its own slug', function () {
    $settings = AdminSettings::fromConfig(adminConfig(['firefly' => ['admin' => ['pages' => ['exclude' => 'overview']]]]));

    expect($settings->allows(''))->toBeFalse()
        ->and($settings->allows('beans'))->toBeTrue();
});

it('bounds the focus explorer independently of the deprecated global graph ceiling', function () {
    $settings = AdminSettings::fromConfig(adminConfig(['firefly' => ['admin' => ['graph' => ['max-nodes' => 0, 'focus' => ['depth' => 999, 'max-rows' => -1, 'max-nodes' => 999, 'max-paths' => -1, 'page-size' => 999], 'starters' => 999, 'modules' => ['max-nodes' => -1]], 'beans' => ['page-size' => 999]]]]));
    expect($settings->graphMaxNodes)->toBe(0)
        ->and($settings->graph->depth)->toBe(4)
        ->and($settings->graph->maxRows)->toBe(4)
        ->and($settings->graph->maxNodes)->toBe(300)
        ->and($settings->graph->maxPaths)->toBe(0)
        ->and($settings->graph->pageSize)->toBe(500)
        ->and($settings->graph->starters)->toBe(50)
        ->and($settings->graph->moduleMaxNodes)->toBe(0)
        ->and($settings->graph->beansPageSize)->toBe(500);
});
