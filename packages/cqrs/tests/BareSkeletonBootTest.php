<?php

declare(strict_types=1);

use Firefly\AutoConfigure\FireflyAutoConfigureServiceProvider;
use Firefly\Context\Boot\ApplicationContext;
use Firefly\Cqrs\CqrsServiceProvider;
use Firefly\Cqrs\CqrsWiringProvider;
use Firefly\Cqrs\Handler\HandlerManifest;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;

/**
 * The bare-skeleton case: firefly/cqrs is on the classpath but the app declares ZERO handlers (no compiled handler
 * manifest bound by anything). Without CqrsWiringProvider's bound()-guarded default empty-manifest bind,
 * CqrsHandlerWiringPass::run()'s make(HandlerManifest::class) would try to autowire the manifest's no-default
 * constructor params and crash boot outright. (The CommandBus-resolves tripwire lives in T13's RealProviderBootTest,
 * where the auto-config actually binds CommandBus.)
 */
it('boots a cqrs-enabled app with zero handlers on the provider default empty manifest', function () {
    $app = new Application;
    $app->instance('config', new Repository(['firefly' => ['cqrs' => []]]));

    $app->register(new FireflyAutoConfigureServiceProvider($app));
    $app->register(new CqrsServiceProvider($app));
    $app->register(new CqrsWiringProvider($app));

    $app->boot();

    /** @var ApplicationContext $context */
    $context = $app->make(ApplicationContext::class);

    expect($context)->toBeInstanceOf(ApplicationContext::class)
        ->and($app->make(HandlerManifest::class)->handlers())->toBe([])
        ->and($app->make(HandlerManifest::class)->destinations())->toBe([]);
});
