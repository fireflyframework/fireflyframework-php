<?php

declare(strict_types=1);

use Firefly\Actuator\ActuatorServiceProvider;
use Firefly\Actuator\ActuatorWiringProvider;
use Firefly\AutoConfigure\FireflyAutoConfigureServiceProvider;
use Firefly\Context\Boot\ApplicationContext;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;

function bootActuatorApp(): Application
{
    $app = new Application;
    $app->instance('config', new Repository(['firefly' => ['management' => ['enabled' => true]]]));
    $app->register(new FireflyAutoConfigureServiceProvider($app));
    $app->register(new ActuatorServiceProvider($app));
    $app->register(new ActuatorWiringProvider($app));
    $app->boot();

    return $app;
}

it('boots a bare skeleton with the actuator providers registered', function () {
    $app = bootActuatorApp();

    expect($app->make(ApplicationContext::class))->toBeInstanceOf(ApplicationContext::class);
});
