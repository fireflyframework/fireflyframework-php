<?php

declare(strict_types=1);

use Firefly\AutoConfigure\AutoConfiguration;
use Firefly\AutoConfigure\FireflyAutoConfigureServiceProvider;
use Firefly\Context\Boot\ApplicationContext;
use Firefly\Scheduling\SchedulingServiceProvider;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Cache\Repository as CacheRepositoryContract;
use Illuminate\Foundation\Application;

it('boots green when discovered alongside the bootstrap provider', function () {
    $app = new Application;
    $app->instance('config', new Repository(['firefly' => ['scheduling' => []]]));
    // From T11 the compiled manifests describe SchedulingAutoConfiguration, whose distributedLock #[Bean] is an
    // eager singleton injecting the Cache Repository — bind one so the eager-singletons phase can resolve it.
    $app->instance(CacheRepositoryContract::class, new CacheRepository(new ArrayStore));

    // The bootstrap binds FireflyKernel and drives auto-configuration; SchedulingServiceProvider is an
    // AutoConfiguration candidate (its final register() records candidacy ONLY — no kernel make), so its
    // manifests now contribute the DistributedLock bean (NoneLock by default) and boot stays green.
    $app->register(new FireflyAutoConfigureServiceProvider($app));
    $app->register(new SchedulingServiceProvider($app));

    $app->boot();

    expect($app->make(ApplicationContext::class))->toBeInstanceOf(ApplicationContext::class)
        ->and(new SchedulingServiceProvider($app))->toBeInstanceOf(AutoConfiguration::class);
});
