<?php

declare(strict_types=1);

use Firefly\AutoConfigure\FireflyAutoConfigureServiceProvider;
use Firefly\Context\Boot\ApplicationContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\FireflyServiceProvider;
use Firefly\Validation\IlluminateValidator;
use Firefly\Validation\Validator;
use Firefly\Web\Dispatch\RouteWiringPass;
use Firefly\Web\Filter\FilterChainRegistrar;
use Firefly\Web\WebServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Kernel as FoundationHttpKernel;
use Illuminate\Routing\Router;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as IlluminateFactory;

it('boots green when discovered alongside the bootstrap provider', function () {
    $app = new Application;
    $app->instance('config', new Repository(['firefly' => []]));

    // A real host app supplies these; a bare Application does not. firefly/validation binds the Validator
    // port (here its shipped IlluminateValidator adapter — a real object, not a fake), and bootstrap/app.php
    // binds the HTTP kernel. Both are needed because the T17 WiringPasses resolve ControllerDispatcher (=>
    // ArgumentResolver => BeanValidator => Validator) and the HTTP kernel (FilterChainRegistrar) at boot.
    $app->instance(Validator::class, new IlluminateValidator(new IlluminateFactory(new Translator(new ArrayLoader, 'en'))));
    $app->instance(HttpKernelContract::class, new FoundationHttpKernel($app, $app->make(Router::class)));

    // The bootstrap binds FireflyKernel FIRST — FireflyServiceProvider::register() makes it unguarded.
    $app->register(new FireflyAutoConfigureServiceProvider($app));
    $app->register(new WebServiceProvider($app));

    $app->boot();

    $passClasses = array_map(static fn (BootPass $pass): string => $pass::class, (new WebServiceProvider($app))->passes());

    expect($app->make(ApplicationContext::class))->toBeInstanceOf(ApplicationContext::class)
        ->and(new WebServiceProvider($app))->toBeInstanceOf(FireflyServiceProvider::class)
        ->and($passClasses)->toBe([RouteWiringPass::class, FilterChainRegistrar::class]);
});
