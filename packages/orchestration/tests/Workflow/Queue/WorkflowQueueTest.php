<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Core\TriggerMode;
use Firefly\Orchestration\Tests\Support\RecordingBusDispatcher;
use Firefly\Orchestration\Tests\Support\TestEngines;
use Firefly\Orchestration\Workflow\Builder\WorkflowBuilder;
use Firefly\Orchestration\Workflow\Queue\BusWorkflowDispatcher;
use Firefly\Orchestration\Workflow\Queue\RunWorkflowJob;

it('dispatches one RunWorkflowJob carrying only the correlation id, on the configured connection and queue', function (): void {
    $bus = new RecordingBusDispatcher;

    (new BusWorkflowDispatcher($bus, 'redis', 'workflows'))->dispatch('wf-1');

    $job = $bus->dispatched[0] ?? null;
    expect($bus->dispatched)->toHaveCount(1)
        ->and($job)->toBeInstanceOf(RunWorkflowJob::class)
        ->and($job instanceof RunWorkflowJob ? [$job->correlationId, $job->connection, $job->queue] : [])->toBe(['wf-1', 'redis', 'workflows']);
});

it('leaves the connection and queue to the application defaults when neither is configured', function (): void {
    $bus = new RecordingBusDispatcher;

    (new BusWorkflowDispatcher($bus))->dispatch('wf-2');

    $job = $bus->dispatched[0] ?? null;
    expect($job instanceof RunWorkflowJob ? [$job->correlationId, $job->connection, $job->queue] : [])->toBe(['wf-2', null, null]);
});

it('survives the queue payload round trip with its connection and queue', function (): void {
    $job = (new RunWorkflowJob('wf-3'))->onConnection('database')->onQueue('workflows');

    $restored = unserialize(serialize($job));

    expect($restored)->toBeInstanceOf(RunWorkflowJob::class)
        ->and($restored instanceof RunWorkflowJob ? [$restored->correlationId, $restored->connection, $restored->queue] : [])->toBe(['wf-3', 'database', 'workflows']);
});

it('runs the PENDING execution it names, and a second delivery returns it untouched', function (): void {
    $engines = TestEngines::create();
    $runs = 0;
    $engines->workflowRegistry->register(WorkflowBuilder::workflow('report')->triggerMode(TriggerMode::Async)
        ->step('build')->handler(static function () use (&$runs): string {
            $runs++;

            return 'pdf';
        })->add()->build());
    $engines->workflows->start('report', correlationId: 'wf-4');

    (new RunWorkflowJob('wf-4'))->handle($engines->workflows);
    $first = $engines->workflows->find('wf-4');
    (new RunWorkflowJob('wf-4'))->handle($engines->workflows);

    expect($first?->status)->toBe(ExecutionStatus::Completed)
        ->and($engines->workflows->find('wf-4')?->toArray())->toBe($first?->toArray())
        ->and($runs)->toBe(1);
});
