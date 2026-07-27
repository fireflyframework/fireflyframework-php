<?php

use Firefly\AutoConfigure\FireflyAutoConfigureServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();

// Firefly boot-order guarantee. Every Firefly capability provider (WebServiceProvider, ActuatorWiringProvider,
// …) extends FireflyServiceProvider, whose register() assumes the FireflyKernel is ALREADY bound — that binding
// is FireflyAutoConfigureServiceProvider's job. Laravel discovers package providers in ALPHABETICAL package
// order, so `firefly/actuator` would otherwise register before `firefly/autoconfigure` and fail to autowire the
// kernel. Registering the AutoConfigure provider in a beforeBootstrapping(RegisterProviders) hook binds the
// kernel BEFORE any package provider is registered; the later auto-discovered instance is a no-op (already
// registered, and the kernel binding is bound()-guarded).
$app->beforeBootstrapping(
    RegisterProviders::class,
    static fn (Application $app) => $app->register(FireflyAutoConfigureServiceProvider::class),
);

return $app;
