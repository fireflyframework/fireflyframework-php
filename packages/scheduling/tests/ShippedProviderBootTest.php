<?php

declare(strict_types=1);

use Firefly\Context\Boot\ApplicationContext;
use Firefly\Scheduling\Lock\DistributedLock;
use Firefly\Scheduling\Lock\NoneLock;
use Firefly\Scheduling\Schedule\ScheduledDescriptor;
use Firefly\Scheduling\Schedule\ScheduledManifest;
use Firefly\Scheduling\SchedulingServiceProvider;
use Firefly\Scheduling\SchedulingWiringProvider;
use Firefly\Scheduling\Tests\Fixtures\ScheduledJobs;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Factory as CacheFactoryContract;
use Illuminate\Contracts\Cache\Repository as CacheRepositoryContract;

/**
 * REAL-PROVIDER end-to-end: the SHIPPED SchedulingServiceProvider (candidacy) + SchedulingWiringProvider
 * (passes) + the bootstrap provider assemble over the live boot pipeline — exactly what `composer require
 * firefly/scheduling` does. It must auto-wire NoneLock (the default) AND lazily register the scheduled task when
 * a Schedule is later resolved (the deferred afterResolving hook, never eager at web boot).
 */
it('auto-wires NoneLock and lazily attaches scheduled tasks when a Schedule resolves', function () {
    // Illuminate\Console\Scheduling\Schedule autowires CacheEventMutex/CacheSchedulingMutex, both of which need
    // Illuminate\Contracts\Cache\Factory, and SchedulingAutoConfiguration's distributedLock #[Bean] needs the
    // Repository — both bound explicitly here (NOT via needs: ['cache']: Laravel's own
    // Application::registerCoreContainerAliases() pre-registers Illuminate\Contracts\Cache\Repository as an ALIAS
    // of 'cache.store', so $app->bound(Cache::class) is already true and the needs-menu's guarded default never
    // fires).
    $app = fireflyApplication(
        ['firefly' => ['scheduling' => []]],
        [SchedulingServiceProvider::class, SchedulingWiringProvider::class],
        bindings: [
            // A pre-bound non-empty manifest; SchedulingWiringProvider's bound()-guarded default backs off.
            ScheduledManifest::class => new ScheduledManifest([
                new ScheduledDescriptor(class: ScheduledJobs::class, method: 'reconcile', cron: '0 3 * * *'),
            ]),
            CacheRepositoryContract::class => new CacheRepository(new ArrayStore),
            CacheFactoryContract::class => new class implements CacheFactoryContract
            {
                public function store($name = null)
                {
                    return new CacheRepository(new ArrayStore);
                }
            },
        ],
    );

    /** @var ApplicationContext $context */
    $context = $app->make(ApplicationContext::class);

    expect($context->get(DistributedLock::class))->toBeInstanceOf(NoneLock::class);

    $schedule = $app->make(Schedule::class);

    expect($schedule->events())->toHaveCount(1)
        ->and($schedule->events()[0]->expression)->toBe('0 3 * * *');
});
