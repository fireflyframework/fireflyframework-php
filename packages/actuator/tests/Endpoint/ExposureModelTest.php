<?php

declare(strict_types=1);

use Firefly\Actuator\Endpoint\ExposureModel;
use Firefly\Config\Config;
use Illuminate\Config\Repository;

/**
 * @param  array<string, mixed>  $management
 */
function exposure(array $management): ExposureModel
{
    return ExposureModel::fromConfig(new Config(new Repository(['firefly' => ['management' => $management]])));
}

it('defaults to exposing only health and info', function () {
    $model = exposure([]);

    expect($model->isExposed('health'))->toBeTrue()
        ->and($model->isExposed('info'))->toBeTrue()
        ->and($model->isExposed('env'))->toBeFalse()
        ->and($model->basePath)->toBe('actuator');
});

it('exposes everything on wildcard but exclude always wins', function () {
    $model = exposure(['endpoints' => ['web' => ['exposure' => ['include' => '*', 'exclude' => 'env,beans']]]]);

    expect($model->isExposed('metrics'))->toBeTrue()
        ->and($model->isExposed('env'))->toBeFalse()
        ->and($model->isExposed('beans'))->toBeFalse();
});

it('honours a custom base-path, stripped of slashes', function () {
    $model = exposure(['endpoints' => ['web' => ['base-path' => '/manage/']]]);

    expect($model->basePath)->toBe('manage');
});

// `*` used to be honoured only in include, so the documented kill switch exposure.exclude=* silently
// exposed everything include named — the inverse of what an operator reaching for it wants.
it('treats * in exclude as a wildcard that shuts everything off', function () {
    $model = exposure(['endpoints' => ['web' => ['exposure' => ['include' => '*', 'exclude' => '*']]]]);

    expect($model->isExposed('health'))->toBeFalse()
        ->and($model->isExposed('info'))->toBeFalse()
        ->and($model->isExposed('env'))->toBeFalse();
});

it('lets exclude=* override even an explicit include list', function () {
    $model = exposure(['endpoints' => ['web' => ['exposure' => ['include' => 'health,info', 'exclude' => '*']]]]);

    expect($model->isExposed('health'))->toBeFalse()
        ->and($model->isExposed('info'))->toBeFalse();
});
