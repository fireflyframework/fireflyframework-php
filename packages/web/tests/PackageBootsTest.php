<?php

declare(strict_types=1);

use Firefly\AutoConfigure\FireflyAutoConfigureServiceProvider;
use Firefly\Context\Boot\ApplicationContext;
use Firefly\Context\Boot\FireflyServiceProvider;
use Firefly\Web\WebServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;

it('boots green when discovered alongside the bootstrap provider', function () {
    $app = new Application;
    $app->instance('config', new Repository(['firefly' => []]));

    // The bootstrap binds FireflyKernel FIRST — FireflyServiceProvider::register() makes it unguarded.
    $app->register(new FireflyAutoConfigureServiceProvider($app));
    $app->register(new WebServiceProvider($app));

    $app->boot();

    expect($app->make(ApplicationContext::class))->toBeInstanceOf(ApplicationContext::class)
        ->and(new WebServiceProvider($app))->toBeInstanceOf(FireflyServiceProvider::class)
        ->and((new WebServiceProvider($app))->passes())->toBe([]);
});
