<?php

declare(strict_types=1);

use Firefly\AutoConfigure\FireflyAutoConfigureServiceProvider;
use Firefly\Context\Boot\ApplicationContext;
use Firefly\Scheduling\Lock\DistributedLock;
use Firefly\Scheduling\Lock\NoneLock;
use Firefly\Scheduling\Schedule\ScheduledManifest;
use Firefly\Scheduling\SchedulingServiceProvider;
use Firefly\Scheduling\SchedulingWiringProvider;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Factory as CacheFactoryContract;
use Illuminate\Contracts\Cache\Repository as CacheRepositoryContract;
use Illuminate\Foundation\Application;

/**
 * COVERAGE GAP closed: ShippedProviderBootTest always hand-binds a non-empty ScheduledManifest before booting, so
 * SchedulingWiringProvider's bound()-guarded default empty-manifest bind is never actually exercised there — every
 * test stays green even if that fallback were deleted. This is the bare-skeleton case: firefly/scheduling is on the
 * classpath but the app declares ZERO #[Scheduled] tasks (no compiled manifest bound by anything). Without the
 * provider's fallback, ScheduleWiringPass::run()'s `$container->make(ScheduledManifest::class)` would try to
 * autowire ScheduledManifest's `array $tasks` constructor parameter (no default) and crash boot outright.
 */
it('boots a scheduling-enabled app with zero #[Scheduled] tasks on the provider default empty manifest', function () {
    $app = new Application;
    $app->instance('config', new Repository(['firefly' => ['scheduling' => []]]));
    $app->instance(CacheRepositoryContract::class, new CacheRepository(new ArrayStore));
    $app->instance(CacheFactoryContract::class, new class implements CacheFactoryContract
    {
        public function store($name = null)
        {
            return new CacheRepository(new ArrayStore);
        }
    });
    // Deliberately NOT binding ScheduledManifest::class — this is the whole point of the test. A bare skeleton
    // app has no #[Scheduled] tasks, so nothing else in the app would ever bind a compiled manifest.

    $app->register(new FireflyAutoConfigureServiceProvider($app));
    $app->register(new SchedulingServiceProvider($app));
    $app->register(new SchedulingWiringProvider($app));

    $app->boot();

    /** @var ApplicationContext $context */
    $context = $app->make(ApplicationContext::class);

    expect($context)->toBeInstanceOf(ApplicationContext::class)
        ->and($context->get(DistributedLock::class))->toBeInstanceOf(NoneLock::class);

    /** @var ScheduledManifest $manifest */
    $manifest = $app->make(ScheduledManifest::class);
    expect($manifest->all())->toBe([]);

    // Resolving the real Schedule fires ScheduleWiringPass's deferred afterResolving hook, which iterates the
    // (empty) manifest — zero events, no crash.
    $schedule = $app->make(Schedule::class);
    expect($schedule->events())->toBe([]);
});
