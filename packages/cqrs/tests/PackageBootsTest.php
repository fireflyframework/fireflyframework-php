<?php

declare(strict_types=1);

use Firefly\Context\Boot\ApplicationContext;
use Firefly\Cqrs\CqrsServiceProvider;
use Firefly\Cqrs\Handler\HandlerManifest;

it('boots green when discovered alongside the bootstrap provider', function () {
    // From T13 the compiled manifests describe CqrsAutoConfiguration, whose commandEventPublisher/domainEventBridge
    // #[Bean]s are eager singletons injecting the HandlerManifest — bind the default empty one (normally
    // CqrsWiringProvider's job, not registered here) so the eager-singletons phase can resolve them.
    $context = bootFireflyApp(
        ['firefly' => []],
        [CqrsServiceProvider::class],
        bindings: [HandlerManifest::class => new HandlerManifest([], [])],
    );

    expect($context)->toBeInstanceOf(ApplicationContext::class);
});
