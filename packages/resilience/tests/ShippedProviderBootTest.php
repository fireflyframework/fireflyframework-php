<?php

declare(strict_types=1);

use Firefly\AutoConfigure\FireflyAutoConfigureServiceProvider;
use Firefly\Context\Boot\ApplicationContext;
use Firefly\Resilience\ResilienceRegistry;
use Firefly\Resilience\ResilienceServiceProvider;
use Firefly\Resilience\Store\CacheResilienceStore;
use Firefly\Resilience\Store\ResilienceStore;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Cache\Repository as CacheRepositoryContract;
use Illuminate\Foundation\Application;

/**
 * REAL-PROVIDER end-to-end: the SHIPPED ResilienceServiceProvider points at the COMMITTED manifests, and the
 * bootstrap provider discovers/assembles them over the live boot pipeline — exactly what `composer require
 * firefly/resilience` does. It must auto-wire the registry + Cache-backed store with an empty config.
 */
it('auto-wires the registry and Cache-backed store by booting the shipped provider', function () {
    $app = new Application;
    $app->instance('config', new Repository(['firefly' => ['resilience' => []]]));
    $app->instance(CacheRepositoryContract::class, new CacheRepository(new ArrayStore));

    $app->register(new ResilienceServiceProvider($app));
    $app->register(new FireflyAutoConfigureServiceProvider($app));

    $app->boot();

    /** @var ApplicationContext $context */
    $context = $app->make(ApplicationContext::class);

    expect($context->get(ResilienceRegistry::class))->toBeInstanceOf(ResilienceRegistry::class)
        ->and($context->get(ResilienceStore::class))->toBeInstanceOf(CacheResilienceStore::class);
});
