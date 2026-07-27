<?php

declare(strict_types=1);

use Firefly\AutoConfigure\AutoConfiguration;
use Firefly\Context\Boot\ApplicationContext;
use Firefly\Scheduling\SchedulingServiceProvider;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Repository as CacheRepositoryContract;

it('boots green when discovered alongside the bootstrap provider', function () {
    // From T11 the compiled manifests describe SchedulingAutoConfiguration, whose distributedLock #[Bean] is an
    // eager singleton injecting the Cache Repository — bind one explicitly (NOT via needs: ['cache']: Laravel's
    // own Application::registerCoreContainerAliases() pre-registers Illuminate\Contracts\Cache\Repository as an
    // ALIAS of 'cache.store', so $app->bound(Cache::class) is already true and the needs-menu's guarded default
    // never fires) so the eager-singletons phase can resolve it.
    // The bootstrap binds FireflyKernel and drives auto-configuration; SchedulingServiceProvider is an
    // AutoConfiguration candidate (its final register() records candidacy ONLY — no kernel make), so its
    // manifests now contribute the DistributedLock bean (NoneLock by default) and boot stays green.
    $app = fireflyApplication(
        ['firefly' => ['scheduling' => []]],
        [SchedulingServiceProvider::class],
        bindings: [CacheRepositoryContract::class => new CacheRepository(new ArrayStore)],
    );

    expect($app->make(ApplicationContext::class))->toBeInstanceOf(ApplicationContext::class)
        ->and(new SchedulingServiceProvider($app))->toBeInstanceOf(AutoConfiguration::class);
});
