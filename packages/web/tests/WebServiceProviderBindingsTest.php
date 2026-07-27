<?php

declare(strict_types=1);

use Firefly\Web\Dispatch\ArgumentResolver;
use Firefly\Web\Dispatch\ControllerDispatcher;
use Firefly\Web\Dispatch\ResponseFactory;
use Firefly\Web\Exception\ExceptionHandlerRegistry;
use Firefly\Web\Http\MessageConverterRegistry;
use Firefly\Web\Route\RouteManifest;
use Firefly\Web\WebServiceProvider;

it('binds every real port its passes and dispatch depend on (M4 bug-7/8/9 lesson)', function () {
    $app = fireflyApplication(['firefly' => []], [WebServiceProvider::class], needs: ['validation', 'http']);

    foreach ([MessageConverterRegistry::class, ArgumentResolver::class, ResponseFactory::class, ExceptionHandlerRegistry::class, ControllerDispatcher::class, RouteManifest::class] as $abstract) {
        expect($app->bound($abstract))->toBeTrue("expected {$abstract} to be bound");
    }
});

it('is idempotent across a simulated second registration (Octane-reset safe)', function () {
    $app = fireflyApplication(['firefly' => []], [WebServiceProvider::class], needs: ['validation', 'http']);
    $first = $app->make(MessageConverterRegistry::class);

    // A second provider instance (as Octane re-registration would do) must not rebind.
    (new WebServiceProvider($app))->register();

    expect($app->make(MessageConverterRegistry::class))->toBe($first);
});
