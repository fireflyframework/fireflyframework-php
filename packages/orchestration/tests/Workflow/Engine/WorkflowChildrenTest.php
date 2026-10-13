<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\ExecutionLock;
use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Core\StepStatus;
use Firefly\Orchestration\Core\TriggerMode;
use Firefly\Orchestration\Tests\Support\ScriptedLock;
use Firefly\Orchestration\Tests\Support\TestEngines;
use Firefly\Orchestration\Workflow\Builder\WorkflowBuilder;
use Firefly\Orchestration\Workflow\Engine\ContinueAsNew;
use Firefly\Orchestration\Workflow\Engine\WorkflowEngine;

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

/**
 * @param  list<string>  $log
 */
function orchestrationPayingParentEngines(array &$log): TestEngines
{
    $engines = TestEngines::create();
    $engines->workflowRegistry->register(WorkflowBuilder::workflow('slow-check')->triggerMode(TriggerMode::Async)
        ->step('run')->handler(static fn (): string => 'checked')->add()->build());
    $engines->workflowRegistry->register(WorkflowBuilder::workflow('paying-parent')
        ->step('pay')->handler(static fn (): string => 'paid')->compensation(static function () use (&$log): void {
            $log[] = 'refund';
        })->add()
        ->step('spawn')->dependsOn('pay')->childWorkflow('slow-check')->handler(static fn (): array => [])->add()
        ->build());

    return $engines;
}

it('cancels a parent waiting on an ASYNC child without re-driving the parent from the child', function (): void {
    $log = [];
    $engines = orchestrationPayingParentEngines($log);

    $waiting = $engines->workflows->start('paying-parent', correlationId: 'pp-1');
    $childId = $waiting->children['spawn'] ?? '';
    $cancelled = $engines->workflows->cancel('pp-1');

    expect($waiting->status)->toBe(ExecutionStatus::Waiting)
        ->and($cancelled->status)->toBe(ExecutionStatus::Cancelled)
        ->and($engines->workflows->find('pp-1')?->status)->toBe(ExecutionStatus::Cancelled)
        ->and($engines->workflows->find($childId)?->status)->toBe(ExecutionStatus::Cancelled)
        ->and($log)->toBe([])
        ->and($engines->deadLetterStore->findByCorrelationId('pp-1'))->toBe([])
        ->and($engines->events->count('onCompleted'))->toBe(2);
});

it('compensates a parent waiting on an ASYNC child exactly once and does not dead-letter it', function (): void {
    $log = [];
    $engines = orchestrationPayingParentEngines($log);

    $waiting = $engines->workflows->start('paying-parent', correlationId: 'pp-2');
    $childId = $waiting->children['spawn'] ?? '';
    $compensated = $engines->workflows->compensate('pp-2');

    expect($compensated->status)->toBe(ExecutionStatus::Cancelled)
        ->and($compensated->failureReason)->toBe('compensated on request')
        ->and($engines->workflows->find('pp-2')?->status)->toBe(ExecutionStatus::Cancelled)
        ->and($engines->workflows->find($childId)?->status)->toBe(ExecutionStatus::Cancelled)
        ->and($log)->toBe(['refund'])
        ->and($engines->deadLetterStore->findByCorrelationId('pp-2'))->toBe([])
        ->and($engines->events->count('onCompleted'))->toBe(2);
});

it('settles a child that finished while its parent was SUSPENDED when the parent is resumed', function (): void {
    $engines = orchestrationChildEngines(TriggerMode::Async);

    $engines->workflows->start('parent', correlationId: 'p-4');
    $engines->workflows->suspend('p-4');
    $engines->dispatcher->runAll($engines->workflows);
    $childId = $engines->workflows->find('p-4')?->children['spawn'] ?? '';
    $suspended = $engines->workflows->find('p-4');

    $resumed = $engines->workflows->resume('p-4');

    expect($engines->workflows->find($childId)?->status)->toBe(ExecutionStatus::Completed)
        ->and($suspended?->status)->toBe(ExecutionStatus::Suspended)
        ->and($resumed->status)->toBe(ExecutionStatus::Completed)
        ->and(data_get($resumed->stepResults, 'spawn.results'))->toBe(['run' => 'checked ada'])
        ->and($resumed->stepResults['after'] ?? null)->toBe('after child')
        ->and($engines->events->count('onChildWorkflowCompleted'))->toBe(1);
});

it('follows a child continue-as-new chain to its last run when the parent re-checks the child', function (): void {
    $engines = TestEngines::create();
    $engines->workflowRegistry->register(WorkflowBuilder::workflow('looping-check')->triggerMode(TriggerMode::Async)
        ->step('run')->handler(static fn (array $input): mixed => ($input['round'] ?? 0) === 0 ? new ContinueAsNew(['round' => 1]) : 'second round')->add()
        ->build());
    $engines->workflowRegistry->register(WorkflowBuilder::workflow('chain-parent')
        ->step('spawn')->childWorkflow('looping-check')->handler(static fn (): array => [])->add()
        ->build());

    $engines->workflows->start('chain-parent', correlationId: 'p-5');
    $engines->workflows->suspend('p-5');
    $engines->dispatcher->runAll($engines->workflows);
    $firstRun = $engines->workflows->find($engines->workflows->find('p-5')?->children['spawn'] ?? '');
    $lastRunId = $firstRun?->variables['continuedAs'] ?? null;

    $resumed = $engines->workflows->resume('p-5');

    expect($firstRun?->status)->toBe(ExecutionStatus::Completed)
        ->and($resumed->status)->toBe(ExecutionStatus::Completed)
        ->and(data_get($resumed->stepResults, 'spawn.correlationId'))->toBe($lastRunId)
        ->and(data_get($resumed->stepResults, 'spawn.results'))->toBe(['run' => 'second round']);
});

it('keeps a child finished while its parent is locked elsewhere, and the next drive of the parent settles it', function (): void {
    $lock = new ScriptedLock;
    $engines = TestEngines::create(['lock' => ['wait' => 0]], lock: $lock);
    $engines->workflowRegistry->register(WorkflowBuilder::workflow('check')->triggerMode(TriggerMode::Async)
        ->step('run')->handler(static fn (): string => 'checked')->add()->build());
    $engines->workflowRegistry->register(WorkflowBuilder::workflow('parent')
        ->step('spawn')->childWorkflow('check')->handler(static fn (): array => [])->add()
        ->build());

    $engines->workflows->start('parent', correlationId: 'p-6');
    $lock->holdElsewhere(ExecutionLock::name('p-6'));
    $ran = $engines->dispatcher->runAll($engines->workflows);
    $whileLocked = $engines->workflows->find('p-6');
    $lock->releaseElsewhere(ExecutionLock::name('p-6'));

    $driven = $engines->workflows->signal('p-6', 'nudge');

    expect($ran[0]->status ?? null)->toBe(ExecutionStatus::Completed)
        ->and($whileLocked?->status)->toBe(ExecutionStatus::Waiting)
        ->and($engines->logger->messages('warning')[0] ?? '')->toContain('could not wake its parent [p-6]')
        ->and($driven->status)->toBe(ExecutionStatus::Completed)
        ->and(data_get($driven->stepResults, 'spawn.results'))->toBe(['run' => 'checked']);
});

it('wakes a parent that continued as new when its ASYNC child completes', function (): void {
    $engines = TestEngines::create();
    $engines->workflowRegistry->register(WorkflowBuilder::workflow('check')->triggerMode(TriggerMode::Async)
        ->step('run')->handler(static fn (): string => 'checked')->add()->build());
    $engines->workflowRegistry->register(WorkflowBuilder::workflow('loop')
        ->step('tick')->handler(static fn (array $input): mixed => ($input['round'] ?? 0) === 0 ? new ContinueAsNew(['round' => 1]) : 'ticked')->add()
        ->step('spawn')->dependsOn('tick')->childWorkflow('check')->handler(static fn (): array => [])->add()
        ->build());

    $waiting = $engines->workflows->start('loop', correlationId: 'loop-1');
    $childId = $waiting->children['spawn'] ?? '';
    $engines->dispatcher->runAll($engines->workflows);

    expect($waiting->correlationId)->not->toBe('loop-1')
        ->and($waiting->status)->toBe(ExecutionStatus::Waiting)
        ->and($waiting->headers[WorkflowEngine::CONTINUED_FROM_HEADER] ?? null)->toBe('loop-1')
        ->and($engines->workflows->find($childId)?->headers)->not->toHaveKey(WorkflowEngine::CONTINUED_FROM_HEADER)
        ->and($engines->workflows->find($waiting->correlationId)?->status)->toBe(ExecutionStatus::Completed);
});

it('fails, compensates and dead-letters a parent whose child workflow is not registered', function (): void {
    $log = [];
    $engines = TestEngines::create();
    $engines->workflowRegistry->register(WorkflowBuilder::workflow('orphan-parent')
        ->step('pay')->handler(static fn (): string => 'paid')->compensation(static function () use (&$log): void {
            $log[] = 'refund';
        })->add()
        ->step('spawn')->dependsOn('pay')->childWorkflow('never-registered')->handler(static fn (): array => [])->add()
        ->build());

    $failed = $engines->workflows->start('orphan-parent', correlationId: 'p-7');

    expect($failed->status)->toBe(ExecutionStatus::Failed)
        ->and($failed->stepStatus('spawn'))->toBe(StepStatus::Failed)
        ->and($failed->failureReason)->toContain('never-registered')
        ->and($log)->toBe(['refund'])
        ->and($engines->workflows->find('p-7')?->status)->toBe(ExecutionStatus::Failed)
        ->and($engines->deadLetterStore->findByCorrelationId('p-7'))->toHaveCount(1);
});

it('fails, compensates and dead-letters a parent whose child is refused by back-pressure', function (): void {
    $log = [];
    $engines = TestEngines::create(['backpressure' => ['max-in-flight' => 1]]);
    $engines->workflowRegistry->register(WorkflowBuilder::workflow('slow-check')->triggerMode(TriggerMode::Async)
        ->step('run')->handler(static fn (): string => 'checked')->add()->build());
    $engines->workflowRegistry->register(WorkflowBuilder::workflow('paying-parent')
        ->step('pay')->handler(static fn (): string => 'paid')->compensation(static function () use (&$log): void {
            $log[] = 'refund';
        })->add()
        ->step('spawn')->dependsOn('pay')->childWorkflow('slow-check')->handler(static fn (): array => [])->add()
        ->build());

    $failed = $engines->workflows->start('paying-parent', correlationId: 'p-8');

    expect($failed->status)->toBe(ExecutionStatus::Failed)
        ->and($failed->stepStatus('spawn'))->toBe(StepStatus::Failed)
        ->and($failed->failureReason)->toContain('max-in-flight')
        ->and($failed->children)->toBe([])
        ->and($log)->toBe(['refund'])
        ->and($engines->deadLetterStore->findByCorrelationId('p-8'))->toHaveCount(1);
});

it('cancels a child whose ASYNC dispatch failed when the parent fails over it', function (): void {
    $log = [];
    $engines = orchestrationPayingParentEngines($log);
    $engines->dispatcher->failNext = new RuntimeException('queue is down');

    $failed = $engines->workflows->start('paying-parent', correlationId: 'p-9');
    $childId = $failed->children['spawn'] ?? '';

    expect($failed->status)->toBe(ExecutionStatus::Failed)
        ->and($failed->failureReason)->toBe('queue is down')
        ->and($engines->workflows->find($childId)?->status)->toBe(ExecutionStatus::Cancelled)
        ->and($log)->toBe(['refund'])
        ->and($engines->deadLetterStore->findByCorrelationId('p-9'))->toHaveCount(1);
});
