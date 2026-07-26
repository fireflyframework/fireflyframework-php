<?php

declare(strict_types=1);

use Firefly\AutoConfigure\FireflyAutoConfigureServiceProvider;
use Firefly\Context\Boot\ApplicationContext;
use Firefly\Observability\ObservabilityServiceProvider;
use Firefly\Observability\ObservabilityWiringProvider;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;

it('boots a bare skeleton with the observability providers registered', function () {
    $app = new Application;
    $app->instance('config', new Repository(['firefly' => ['observability' => ['metrics' => ['enabled' => true]]]]));
    $app->register(new FireflyAutoConfigureServiceProvider($app));
    $app->register(new ObservabilityServiceProvider($app));
    $app->register(new ObservabilityWiringProvider($app));
    $app->boot();

    expect($app->make(ApplicationContext::class))->toBeInstanceOf(ApplicationContext::class);
});
