<?php

declare(strict_types=1);

use Firefly\AutoConfigure\FireflyAutoConfigureServiceProvider;
use Firefly\Context\Boot\ApplicationContext;
use Firefly\Context\Boot\FireflyServiceProvider;
use Firefly\Resilience\ResilienceServiceProvider;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Cache\Repository as CacheRepositoryContract;
use Illuminate\Foundation\Application;

it('boots green when discovered alongside the bootstrap provider', function () {
    $app = new Application;
    $app->instance('config', new Repository(['firefly' => []]));
    // A real app always has a cache store; bind one so the T7 eager registry/store bean resolves here too.
    $app->instance(CacheRepositoryContract::class, new CacheRepository(new ArrayStore));

    $app->register(new FireflyAutoConfigureServiceProvider($app));
    $app->register(new ResilienceServiceProvider($app));

    $app->boot();

    expect($app->make(ApplicationContext::class))->toBeInstanceOf(ApplicationContext::class)
        ->and(new ResilienceServiceProvider($app))->toBeInstanceOf(FireflyServiceProvider::class);
});
