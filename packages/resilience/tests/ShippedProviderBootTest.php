<?php

declare(strict_types=1);

use Firefly\Resilience\ResilienceRegistry;
use Firefly\Resilience\ResilienceServiceProvider;
use Firefly\Resilience\Store\CacheResilienceStore;
use Firefly\Resilience\Store\ResilienceStore;

/**
 * REAL-PROVIDER end-to-end: the SHIPPED ResilienceServiceProvider points at the COMMITTED manifests, and the
 * bootstrap provider discovers/assembles them over the live boot pipeline — exactly what `composer require
 * firefly/resilience` does. It must auto-wire the registry + Cache-backed store with an empty config.
 */
it('auto-wires the registry and Cache-backed store by booting the shipped provider', function () {
    $context = bootFireflyApp(
        ['firefly' => ['resilience' => []]],
        [ResilienceServiceProvider::class],
        needs: ['cache'],
    );

    expect($context->get(ResilienceRegistry::class))->toBeInstanceOf(ResilienceRegistry::class)
        ->and($context->get(ResilienceStore::class))->toBeInstanceOf(CacheResilienceStore::class);
});
