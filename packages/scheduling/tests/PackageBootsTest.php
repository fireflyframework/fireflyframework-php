<?php

declare(strict_types=1);

use Firefly\AutoConfigure\AutoConfiguration;
use Firefly\AutoConfigure\FireflyAutoConfigureServiceProvider;
use Firefly\Context\Boot\ApplicationContext;
use Firefly\Scheduling\SchedulingServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;

it('boots green when discovered alongside the bootstrap provider', function () {
    $app = new Application;
    $app->instance('config', new Repository(['firefly' => ['scheduling' => []]]));

    // The bootstrap binds FireflyKernel and drives auto-configuration; SchedulingServiceProvider is an
    // AutoConfiguration candidate (its final register() records candidacy ONLY — no kernel make), so its
    // T8-empty manifests contribute no beans and boot stays green.
    $app->register(new FireflyAutoConfigureServiceProvider($app));
    $app->register(new SchedulingServiceProvider($app));

    $app->boot();

    expect($app->make(ApplicationContext::class))->toBeInstanceOf(ApplicationContext::class)
        ->and(new SchedulingServiceProvider($app))->toBeInstanceOf(AutoConfiguration::class);
});
