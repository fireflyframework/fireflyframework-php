<?php

declare(strict_types=1);

use Firefly\Context\Boot\ApplicationContext;
use Firefly\Cqrs\CqrsServiceProvider;
use Firefly\Cqrs\CqrsWiringProvider;
use Firefly\Cqrs\Handler\HandlerManifest;
use Firefly\Validation\Validator;
use Firefly\Web\WebServiceProvider;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Application;

it('boots a bare cqrs app with AutoConfigure first and an explicit binding', function () {
    $context = bootFireflyApp(
        config: ['firefly' => ['cqrs' => []]],
        providers: [CqrsServiceProvider::class, CqrsWiringProvider::class],
        bindings: [HandlerManifest::class => new HandlerManifest([], [])],
    );

    expect($context)->toBeInstanceOf(ApplicationContext::class)
        ->and($context->has(HandlerManifest::class))->toBeTrue();
});

it('applies the validation+http missing-bindings menu via $needs for a web boot', function () {
    $app = fireflyApplication(
        config: ['firefly' => []],
        providers: [WebServiceProvider::class],
        needs: ['validation', 'http'],
    );

    expect($app)->toBeInstanceOf(Application::class)
        ->and($app->bound(Validator::class))->toBeTrue()
        ->and($app->bound(HttpKernelContract::class))->toBeTrue()
        ->and($app->make(ApplicationContext::class))->toBeInstanceOf(ApplicationContext::class);
});
