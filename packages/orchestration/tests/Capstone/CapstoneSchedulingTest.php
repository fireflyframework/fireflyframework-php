<?php

declare(strict_types=1);

use Firefly\Orchestration\Recovery\OrchestrationRecoveryTask;
use Firefly\Orchestration\Recovery\OrchestrationTickTask;
use Firefly\Orchestration\Tests\CapstoneFixtures\Ledger;
use Firefly\Orchestration\Tests\Support\OrchestrationCapstoneTestCase;
use Firefly\Scheduling\Schedule\ScheduledDescriptor;
use Firefly\Scheduling\Schedule\ScheduledManifest;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

uses(OrchestrationCapstoneTestCase::class);

it('puts the tick and the recovery sweep on the framework schedule under their locks', function (): void {
    /** @var OrchestrationCapstoneTestCase $this */
    $tasks = array_map(static fn (ScheduledDescriptor $task): string => "{$task->class}::{$task->method} {$task->fixedRate} {$task->lockName}", $this->app()->make(ScheduledManifest::class)->all());

    expect($tasks)->toContain(OrchestrationTickTask::class.'::tick 60s firefly:orchestration:tick')
        ->toContain(OrchestrationRecoveryTask::class.'::recover 3600s firefly:orchestration:recovery');
});

it('wires #[ScheduledSaga] onto Laravel\'s schedule and starts the saga when it runs', function (): void {
    /** @var OrchestrationCapstoneTestCase $this */
    $events = array_values(array_filter(
        $this->app()->make(Schedule::class)->events(),
        static fn (Event $event): bool => $event->description === 'nightly checkout',
    ));

    expect($events)->toHaveCount(1)
        ->and($events[0]->expression)->toBe('0 2 * * *');

    $events[0]->run($this->app());

    expect($this->app()->make(Ledger::class)->lines)->toContain('reserve 1', 'charge 5 for r-1');
});
