<?php

declare(strict_types=1);

use Firefly\AutoConfigure\FireflyAutoConfigureServiceProvider;
use Firefly\Web\Dispatch\ArgumentResolver;
use Firefly\Web\Dispatch\ControllerDispatcher;
use Firefly\Web\Dispatch\ResponseFactory;
use Firefly\Web\Exception\ExceptionHandlerRegistry;
use Firefly\Web\Http\MessageConverterRegistry;
use Firefly\Web\Route\RouteManifest;
use Firefly\Web\WebServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;

it('binds every real port its passes and dispatch depend on (M4 bug-7/8/9 lesson)', function () {
    $app = new Application;
    $app->instance('config', new Repository(['firefly' => []]));
    $app->register(new FireflyAutoConfigureServiceProvider($app));
    $app->register(new WebServiceProvider($app));

    foreach ([MessageConverterRegistry::class, ArgumentResolver::class, ResponseFactory::class, ExceptionHandlerRegistry::class, ControllerDispatcher::class, RouteManifest::class] as $abstract) {
        expect($app->bound($abstract))->toBeTrue("expected {$abstract} to be bound");
    }
});

it('is idempotent across a simulated second registration (Octane-reset safe)', function () {
    $app = new Application;
    $app->instance('config', new Repository(['firefly' => []]));
    $app->register(new FireflyAutoConfigureServiceProvider($app));

    $app->register(new WebServiceProvider($app));
    $first = $app->make(MessageConverterRegistry::class);

    // A second provider instance (as Octane re-registration would do) must not rebind.
    (new WebServiceProvider($app))->register();

    expect($app->make(MessageConverterRegistry::class))->toBe($first);
});
