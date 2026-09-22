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

it('applies the #[Scheduled] zone to the registered Event as its timezone', function () {
    $container = new Container;
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
        new ScheduledDescriptor(class: ScheduledJobs::class, method: 'reconcile', cron: '0 3 * * *', zone: 'America/New_York'),
    ]));

    (new ScheduleWiringPass)->run(scheduleWiringContext($container));

    $schedule = $container->make(Schedule::class); // resolving the Schedule fires the hook

    expect($schedule->events())->toHaveCount(1)
        ->and($schedule->events()[0]->timezone)->toBe('America/New_York'); // was captured but never applied
});

it('runs at the WiringPasses boot phase, order 0', function () {
    // Guards the ordinal: the deferred hook must be wired at the instance stage (WiringPasses/1000), after the
    // DistributedLock bean is resolvable. Mutating phase() to an earlier phase must fail here.
    expect((new ScheduleWiringPass)->phase())->toBe(BootPhase::WiringPasses)
        ->and((new ScheduleWiringPass)->order())->toBe(0);
});

/**
 * @param  list<ScheduledDescriptor>  $descriptors
 */
function scheduleFor(array $descriptors): Schedule
{
    $container = new Container;
    Container::setInstance($container);
    $container->instance(CacheFactoryContract::class, new class implements CacheFactoryContract
    {
        public function store($name = null)
        {
            return new CacheRepository(new ArrayStore);
        }
    });
    $container->instance(DistributedLock::class, new NoneLock);
    $container->instance(ScheduledManifest::class, new ScheduledManifest($descriptors));

    (new ScheduleWiringPass)->run(scheduleWiringContext($container));

    return $container->make(Schedule::class);
}

/*
 * A SUB-MINUTE RATE USED TO BE ROUNDED UP TO A MINUTE. Laravel has run sub-minute tasks under `schedule:work`
 * (and a once-a-minute `schedule:run`) since 10.x — everySecond() … everyThirtySeconds(), any divisor of
 * sixty — but the wiring bucketed everything at or below 60 s onto everyMinute(), so `fixedRate: '10s'` ran
 * six times less often than it said and an application that needed a ten-second sweep had to write its own
 * loop. The rate is now mapped to the smallest supported sub-minute cadence that is not shorter than it.
 */
it('maps a sub-minute fixedRate onto Laravel\'s repeat-seconds cadence', function () {
    $schedule = scheduleFor([
        new ScheduledDescriptor(class: ScheduledJobs::class, method: 'reconcile', fixedRate: '30s'),
        new ScheduledDescriptor(class: ScheduledJobs::class, method: 'reconcile', fixedRate: '10s'),
        new ScheduledDescriptor(class: ScheduledJobs::class, method: 'reconcile', fixedRate: '250ms'),
    ]);

    [$thirty, $ten, $subSecond] = $schedule->events();

    expect($thirty->repeatSeconds)->toBe(30)
        ->and($thirty->expression)->toBe('* * * * *')
        ->and($ten->repeatSeconds)->toBe(10)
        ->and($subSecond->repeatSeconds)->toBe(1);
});

it('rounds a rate that is not a divisor of sixty UP to the next supported cadence, never down', function () {
    $schedule = scheduleFor([
        new ScheduledDescriptor(class: ScheduledJobs::class, method: 'reconcile', fixedRate: '7s'),
        new ScheduledDescriptor(class: ScheduledJobs::class, method: 'reconcile', fixedRate: '45s'),
        new ScheduledDescriptor(class: ScheduledJobs::class, method: 'reconcile', fixedRate: '60s'),
    ]);

    [$seven, $fortyFive, $sixty] = $schedule->events();

    // 7 s -> 10 s (the next divisor of 60); 45 s and 60 s -> every minute, with no repeat-seconds at all.
    expect($seven->repeatSeconds)->toBe(10)
        ->and($fortyFive->repeatSeconds)->toBeNull()
        ->and($fortyFive->expression)->toBe('* * * * *')
        ->and($sixty->repeatSeconds)->toBeNull();
});

it('keeps the minute-and-above buckets as they were', function () {
    $schedule = scheduleFor([
        new ScheduledDescriptor(class: ScheduledJobs::class, method: 'reconcile', fixedRate: '5m'),
        new ScheduledDescriptor(class: ScheduledJobs::class, method: 'reconcile', fixedDelay: '1h'),
    ]);

    [$five, $hour] = $schedule->events();

    expect($five->expression)->toBe('*/5 * * * *')
        ->and($hour->expression)->toBe('0 * * * *');
});
