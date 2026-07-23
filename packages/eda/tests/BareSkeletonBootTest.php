<?php

declare(strict_types=1);

use Firefly\AutoConfigure\FireflyAutoConfigureServiceProvider;
use Firefly\Context\Boot\ApplicationContext;
use Firefly\Eda\Bus\InMemoryEventBus;
use Firefly\Eda\EdaServiceProvider;
use Firefly\Eda\EdaWiringProvider;
use Firefly\Eda\EventPublisher;
use Firefly\Eda\Listener\EventListenerManifest;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;

/**
 * The bare-skeleton case: firefly/eda is on the classpath but the app declares ZERO #[EventListener]s (no compiled
 * manifest bound by anything). Without EdaWiringProvider's bound()-guarded default empty-manifest bind,
 * EventListenerWiringPass::run()'s make(EventListenerManifest::class) would try to autowire the manifest's
 * no-default array constructor param and crash boot outright.
 */
it('boots an eda-enabled app with zero #[EventListener]s on the provider default empty manifest', function () {
    $app = new Application;
    $app->instance('config', new Repository(['firefly' => ['eda' => []]]));

    $app->register(new FireflyAutoConfigureServiceProvider($app));
    $app->register(new EdaServiceProvider($app));
    $app->register(new EdaWiringProvider($app));

    $app->boot();

    /** @var ApplicationContext $context */
    $context = $app->make(ApplicationContext::class);

    expect($context)->toBeInstanceOf(ApplicationContext::class)
        ->and($context->get(EventPublisher::class))->toBeInstanceOf(InMemoryEventBus::class)
        ->and($app->make(EventListenerManifest::class)->all())->toBe([]);
});
