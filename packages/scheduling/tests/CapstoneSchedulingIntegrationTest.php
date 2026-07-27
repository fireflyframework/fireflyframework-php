<?php

declare(strict_types=1);

use Firefly\Scheduling\Tests\CapstoneFixtures\SpyCounter;
use Firefly\Scheduling\Tests\Support\SchedulingCapstoneTestCase;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;

uses(SchedulingCapstoneTestCase::class);

/** The scheduled fixture's lock name (from #[Scheduled(lock: true)] on ReconcileJob::run → "Class::method"). */
const CAPSTONE_LOCK_NAME = 'Firefly\\Scheduling\\Tests\\CapstoneFixtures\\ReconcileJob::run';

/** Resolve the Schedule (this triggers the deferred afterResolving hook) and return our single registered event. */
function capstoneEvent(Schedule $schedule): Event
{
    $events = $schedule->events();
    expect($events)->not->toBeEmpty();

    return $events[0];
}

it('registers the #[Scheduled] task on the real Schedule via the deferred afterResolving hook', function () {
    /** @var SchedulingCapstoneTestCase $this */
    /** @var Schedule $schedule */
    $schedule = $this->app()->make(Schedule::class);

    expect($schedule->events())->toHaveCount(1);
});

it('runs the task body when the lock is free', function () {
    /** @var SchedulingCapstoneTestCase $this */
    $spy = $this->app()->make(SpyCounter::class);

    /** @var Schedule $schedule */
    $schedule = $this->app()->make(Schedule::class);
    capstoneEvent($schedule)->run($this->app());

    expect($spy->count)->toBe(1);
});

it('SKIPS the task body when the lock is already held elsewhere', function () {
    /** @var SchedulingCapstoneTestCase $this */
    // Pre-acquire the REAL Laravel lock for the task's lock name (a foreign owner, kept held for the test's
    // lifetime). The real config-selected CacheLock inside the wiring then sees it held → tryAcquire() returns
    // false → the guard skips the tick. This exercises the full real stack (no test double).
    $held = Cache::lock(CAPSTONE_LOCK_NAME, 60);
    expect($held->get())->toBeTrue();

    $spy = $this->app()->make(SpyCounter::class);

    /** @var Schedule $schedule */
    $schedule = $this->app()->make(Schedule::class);
    capstoneEvent($schedule)->run($this->app());

    expect($spy->count)->toBe(0);

    $held->release();
});
