<?php

declare(strict_types=1);

use Firefly\Scheduling\Schedule\InitialDelayGate;
use Firefly\Scheduling\Tests\DelayFixtures\DelayedTask;
use Firefly\Scheduling\Tests\DelayFixtures\PromptTask;
use Firefly\Scheduling\Tests\Support\InitialDelayCapstoneTestCase;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;

uses(InitialDelayCapstoneTestCase::class);

/** The anchor key the gate writes for DelayFixtures\DelayedTask::run. */
const DELAY_ANCHOR_KEY = InitialDelayGate::ANCHOR_PREFIX.DelayedTask::class.'::run';

/**
 * The two registered Events, told apart by the cadence each fixture declares: DelayedTask is the one-minute
 * one ('* * * * *'), PromptTask the five-minute one. Scan order is filesystem order and must not be assumed.
 *
 * @return array{Event, Event}
 */
function delayCapstoneEvents(Schedule $schedule): array
{
    $events = $schedule->events();
    expect($events)->toHaveCount(2);

    $byExpression = [];
    foreach ($events as $event) {
        $byExpression[$event->expression] = $event;
    }

    expect($byExpression)->toHaveKeys(['* * * * *', '*/5 * * * *']);

    return [$byExpression['* * * * *'], $byExpression['*/5 * * * *']];
}

/**
 * THE WHOLE PIPELINE, not the gate on its own: the real scanner reads `#[Scheduled(fixedRate: '1m',
 * initialDelay: '10m')]` off a real class, the real manifest carries it, the real ScheduleWiringPass wires it
 * onto Laravel's real Schedule, and the predicate that decides the tick is read off the application's real
 * cache store. Before this, every one of those steps ran and the delay still did nothing.
 */
it('does not let a task with an initialDelay run until the window has passed', function () {
    /** @var InitialDelayCapstoneTestCase $this */
    /** @var Schedule $schedule */
    $schedule = $this->app()->make(Schedule::class);
    [$delayed] = delayCapstoneEvents($schedule);

    // The delay is NOT expressed as a cadence — the attribute's own minute cadence is still what was wired —
    // it is a filter on top of it. The first observation anchors the window and is itself refused.
    expect($delayed->filtersPass($this->app()))->toBeFalse();

    // The anchor, moved a second past the ten-minute window: the same Event now runs.
    Cache::put(DELAY_ANCHOR_KEY, microtime(true) - 601.0);

    expect($delayed->filtersPass($this->app()))->toBeTrue();
});

it('holds nothing back that did not ask to be held back', function () {
    /** @var InitialDelayCapstoneTestCase $this */
    /** @var Schedule $schedule */
    $schedule = $this->app()->make(Schedule::class);
    [, $prompt] = delayCapstoneEvents($schedule);

    expect($prompt->filtersPass($this->app()))->toBeTrue()
        ->and(Cache::has(InitialDelayGate::ANCHOR_PREFIX.PromptTask::class.'::run'))->toBeFalse();
});

it('anchors in the application cache store, so the next schedule:run process measures the same window', function () {
    /** @var InitialDelayCapstoneTestCase $this */
    /** @var Schedule $schedule */
    $schedule = $this->app()->make(Schedule::class);
    [$delayed] = delayCapstoneEvents($schedule);

    expect(Cache::get(DELAY_ANCHOR_KEY))->toBeNull();

    $delayed->filtersPass($this->app());
    $anchor = Cache::get(DELAY_ANCHOR_KEY);

    expect($anchor)->toBeFloat();

    // A second observation must READ that anchor, never rewrite it — otherwise a per-minute cron would reset
    // the window every tick and the task would never run at all.
    $delayed->filtersPass($this->app());

    expect(Cache::get(DELAY_ANCHOR_KEY))->toBe($anchor);
});
