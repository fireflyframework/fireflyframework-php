<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Config\Profile\Profiles;
use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPhase;
use Firefly\Context\Condition\ConditionEvaluationReport;
use Firefly\Context\Condition\ConditionEvaluator;
use Firefly\Context\Definition\BeanDefinitionRegistry;
use Firefly\Scheduling\Boot\ScheduleWiringPass;
use Firefly\Scheduling\Lock\DistributedLock;
use Firefly\Scheduling\Lock\NoneLock;
use Firefly\Scheduling\Schedule\ScheduledDescriptor;
use Firefly\Scheduling\Schedule\ScheduledManifest;
use Firefly\Scheduling\Tests\Fixtures\ScheduledJobs;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Factory as CacheFactoryContract;

function scheduleWiringContext(Container $container): BootContext
{
    $config = new Config(new Repository([]));
    $profiles = new Profiles([]);

    return new BootContext(
        container: $container,
        definitions: new BeanDefinitionRegistry,
        config: $config,
        profiles: $profiles,
        conditions: new ConditionEvaluator($config, $profiles),
        report: new ConditionEvaluationReport,
    );
}

it('lazily registers each scheduled task only when a Schedule is resolved', function () {
    $container = new Container;
    // Schedule's constructor resolves its mutexes from Container::getInstance(); a one-method Cache Factory
    // satisfies CacheEventMutex/CacheSchedulingMutex without a full app.
    Container::setInstance($container);
    $container->instance(CacheFactoryContract::class, new class implements CacheFactoryContract
    {
        public function store($name = null)
        {
            return new CacheRepository(new ArrayStore);
        }
    });
    $container->instance(DistributedLock::class, new NoneLock);
    $container->instance(ScheduledManifest::class, new ScheduledManifest([
        new ScheduledDescriptor(class: ScheduledJobs::class, method: 'reconcile', cron: '0 3 * * *', lockName: ScheduledJobs::class.'::reconcile'),
    ]));

    (new ScheduleWiringPass)->run(scheduleWiringContext($container));

    // The pass MUST NOT have registered anything yet — it only wires an afterResolving hook.
    $schedule = $container->make(Schedule::class); // resolving the Schedule fires the hook

    expect($schedule->events())->toHaveCount(1)
        ->and($schedule->events()[0]->expression)->toBe('0 3 * * *'); // cron applied verbatim (default is '* * * * *')
});

it('runs at the WiringPasses boot phase, order 0', function () {
    // Guards the ordinal: the deferred hook must be wired at the instance stage (WiringPasses/1000), after the
    // DistributedLock bean is resolvable. Mutating phase() to an earlier phase must fail here.
    expect((new ScheduleWiringPass)->phase())->toBe(BootPhase::WiringPasses)
        ->and((new ScheduleWiringPass)->order())->toBe(0);
});
