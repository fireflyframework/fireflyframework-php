<?php

declare(strict_types=1);

use Firefly\AutoConfigure\FireflyAutoConfigureServiceProvider;
use Firefly\Context\Boot\ApplicationContext;
use Firefly\Data\DataServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;

it('boots green when discovered alongside the bootstrap provider', function () {
    $app = new Application;
    $app->instance('config', new Repository(['firefly' => []]));

    $app->register(new DataServiceProvider($app));
    $app->register(new FireflyAutoConfigureServiceProvider($app));

    $app->boot();

    expect($app->make(ApplicationContext::class))->toBeInstanceOf(ApplicationContext::class);
});
