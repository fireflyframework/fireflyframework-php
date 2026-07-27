<?php

declare(strict_types=1);

use Firefly\Context\Boot\ApplicationContext;
use Firefly\Cqrs\CqrsServiceProvider;
use Firefly\Cqrs\CqrsWiringProvider;
use Firefly\Cqrs\Handler\HandlerManifest;

/**
 * The bare-skeleton case: firefly/cqrs is on the classpath but the app declares ZERO handlers (no compiled handler
 * manifest bound by anything). Without CqrsWiringProvider's bound()-guarded default empty-manifest bind,
 * CqrsHandlerWiringPass::run()'s make(HandlerManifest::class) would try to autowire the manifest's no-default
 * constructor params and crash boot outright. (The CommandBus-resolves tripwire lives in T13's RealProviderBootTest,
 * where the auto-config actually binds CommandBus.)
 */
it('boots a cqrs-enabled app with zero handlers on the provider default empty manifest', function () {
    $context = bootFireflyApp(['firefly' => ['cqrs' => []]], [CqrsServiceProvider::class, CqrsWiringProvider::class]);

    /** @var HandlerManifest $manifest */
    $manifest = $context->get(HandlerManifest::class);

    expect($context)->toBeInstanceOf(ApplicationContext::class)
        ->and($manifest->handlers())->toBe([])
        ->and($manifest->destinations())->toBe([]);
});
