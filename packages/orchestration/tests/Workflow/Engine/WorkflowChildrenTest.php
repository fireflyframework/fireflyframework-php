<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Core\TriggerMode;
use Firefly\Orchestration\Tests\Support\TestEngines;
use Firefly\Orchestration\Workflow\Builder\WorkflowBuilder;

function orchestrationChildEngines(TriggerMode $childMode, bool $childFails = false): TestEngines
{
    $engines = TestEngines::create();
    $engines->workflowRegistry->register(WorkflowBuilder::workflow('check')->triggerMode($childMode)
        ->step('run')->handler(static function (array $input) use ($childFails): string {
            if ($childFails) {
                throw new RuntimeException('check failed');
            }

            return 'checked '.(is_string($input['subject'] ?? null) ? $input['subject'] : '?');
        })->add()->build());
    $engines->workflowRegistry->register(WorkflowBuilder::workflow('parent')
        ->step('spawn')->childWorkflow('check')->handler(static fn (): array => ['subject' => 'ada'])->add()
        ->step('after')->dependsOn('spawn')->handler(static fn (): string => 'after child')->add()
        ->build());

    return $engines;
}

it('runs a SYNC child inline and takes its results as the step result', function (): void {
    $engines = orchestrationChildEngines(TriggerMode::Sync);

    $parent = $engines->workflows->start('parent', correlationId: 'p-1');
    $childId = $parent->children['spawn'] ?? '';

    expect($parent->status)->toBe(ExecutionStatus::Completed)
        ->and(data_get($parent->stepResults, 'spawn.results'))->toBe(['run' => 'checked ada'])
        ->and($engines->workflows->find($childId)?->parentCorrelationId)->toBe('p-1')
        ->and($engines->events->count('onChildWorkflowStarted'))->toBe(1);
});

it('waits durably for an ASYNC child and resumes when the worker finishes it', function (): void {
    $engines = orchestrationChildEngines(TriggerMode::Async);

    $waiting = $engines->workflows->start('parent', correlationId: 'p-2');
    $engines->dispatcher->runAll($engines->workflows);

    expect($waiting->status)->toBe(ExecutionStatus::Waiting)
        ->and($waiting->waitingOn)->toBe('spawn')
        ->and($engines->workflows->find('p-2')?->status)->toBe(ExecutionStatus::Completed)
        ->and($engines->events->count('onChildWorkflowCompleted'))->toBe(1);
});

it('fails the parent step when the child fails', function (): void {
    $engines = orchestrationChildEngines(TriggerMode::Sync, childFails: true);

    $parent = $engines->workflows->start('parent', correlationId: 'p-3');

    expect($parent->status)->toBe(ExecutionStatus::Failed)
        ->and($parent->failureReason)->toContain('ended FAILED: check failed');
});
