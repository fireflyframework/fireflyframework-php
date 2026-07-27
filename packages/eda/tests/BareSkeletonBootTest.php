<?php

declare(strict_types=1);

use Firefly\Context\Boot\ApplicationContext;
use Firefly\Eda\Bus\InMemoryEventBus;
use Firefly\Eda\EdaServiceProvider;
use Firefly\Eda\EdaWiringProvider;
use Firefly\Eda\EventPublisher;
use Firefly\Eda\Listener\EventListenerManifest;

/**
 * The bare-skeleton case: firefly/eda is on the classpath but the app declares ZERO #[EventListener]s (no compiled
 * manifest bound by anything). Without EdaWiringProvider's bound()-guarded default empty-manifest bind,
 * EventListenerWiringPass::run()'s make(EventListenerManifest::class) would try to autowire the manifest's
 * no-default array constructor param and crash boot outright.
 */
it('boots an eda-enabled app with zero #[EventListener]s on the provider default empty manifest', function () {
    $context = bootFireflyApp(['firefly' => ['eda' => []]], [EdaServiceProvider::class, EdaWiringProvider::class]);

    /** @var EventListenerManifest $manifest */
    $manifest = $context->get(EventListenerManifest::class);

    expect($context)->toBeInstanceOf(ApplicationContext::class)
        ->and($context->get(EventPublisher::class))->toBeInstanceOf(InMemoryEventBus::class)
        ->and($manifest->all())->toBe([]);
});
