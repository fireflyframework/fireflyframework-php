<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Core\StepStatus;
use Firefly\Orchestration\Persistence\ExecutionState;
use Firefly\Orchestration\Saga\Builder\SagaBuilder;
use Firefly\Orchestration\Tcc\Builder\TccBuilder;
use Firefly\Orchestration\Tests\Support\ExecutionPersistenceContract;
use Firefly\Orchestration\Tests\Support\RacingExecutionPersistence;
use Firefly\Orchestration\Tests\Support\TestEngines;
use Firefly\Orchestration\Workflow\Builder\WorkflowBuilder;
use Firefly\Orchestration\Workflow\Engine\WorkflowEngine;

it('cleans up terminal executions past the retention period and never touches waiting ones', function (): void {
    $engines = TestEngines::create(['persistence' => ['retention-period' => 'P1D']]);
    $engines->persistence->save(ExecutionPersistenceContract::state('old', ExecutionPattern::Saga, ExecutionStatus::Completed, '2026-10-10T09:00:00+00:00'));
    $engines->persistence->save(ExecutionPersistenceContract::state('recent', ExecutionPattern::Saga, ExecutionStatus::Failed, '2026-10-12T09:00:00+00:00'));
    $engines->persistence->save(ExecutionPersistenceContract::state('parked', ExecutionPattern::Workflow, ExecutionStatus::Waiting, '2026-10-01T09:00:00+00:00'));

    $report = $engines->recovery->recover();

    expect($report->cleaned)->toBe(1)
        ->and($report->stale)->toBe([])
        ->and($engines->persistence->findById('old'))->toBeNull()
        ->and($engines->persistence->findById('parked'))->not->toBeNull();
});

it('recovers an interrupted workflow by driving it again', function (): void {
    $engines = TestEngines::create(['recovery' => ['action' => 'resume', 'stale-threshold' => '1m']]);
    $engines->workflowRegistry->register(WorkflowBuilder::workflow('w')->step('a')->handler(static fn (): string => 'a')->add()->build());
    $engines->persistence->save(ExecutionPersistenceContract::state('w-1', ExecutionPattern::Workflow, ExecutionStatus::Running, '2026-10-12T09:00:00+00:00', [
        'executionName' => 'w', 'stepStatuses' => ['a' => 'RUNNING'], 'stepResults' => [],
    ]));

    $report = $engines->recovery->recover();

    expect($report->resumed)->toBe(['w-1'])
        ->and($engines->persistence->findById('w-1')?->status)->toBe(ExecutionStatus::Completed);
});

/**
 * Workflow w: a -> b -> c, with compensations on a and b; every run and undo is logged.
 *
 * @param  list<string>  $log
 */
function compensatingWorkflow(TestEngines $engines, array &$log): void
{
    $engines->workflowRegistry->register(WorkflowBuilder::workflow('w')
        ->step('a')->handler(static function () use (&$log): string {
            $log[] = 'run a';

            return 'a';
        })->compensation(static function () use (&$log): void {
            $log[] = 'undo a';
        })->add()
        ->step('b')->dependsOn('a')->handler(static function () use (&$log): string {
            $log[] = 'run b';

            return 'b';
        })->compensation(static function () use (&$log): void {
            $log[] = 'undo b';
        })->add()
        ->step('c')->dependsOn('b')->handler(static function () use (&$log): string {
            $log[] = 'run c';

            return 'c';
        })->add()
        ->build());
}

it('finishes a compensation on request a crash interrupted instead of driving the workflow forward', function (): void {
    $engines = TestEngines::create(['recovery' => ['action' => 'resume', 'stale-threshold' => '1m']]);
    $log = [];
    compensatingWorkflow($engines, $log);
    $engines->persistence->save(ExecutionPersistenceContract::state('w-1', ExecutionPattern::Workflow, ExecutionStatus::Compensating, '2026-10-12T09:00:00+00:00', [
        'executionName' => 'w', 'stepStatuses' => ['a' => 'DONE', 'b' => 'COMPENSATED'], 'stepResults' => ['a' => 'a', 'b' => 'b'],
        'completionOrder' => ['a', 'b'], 'variables' => [WorkflowEngine::COMPENSATION_REQUESTED => true],
    ]));

    $report = $engines->recovery->recover();
    $state = $engines->persistence->findById('w-1');

    expect($report->resumed)->toBe(['w-1'])
        ->and($log)->toBe(['undo a'])
        ->and($state?->status)->toBe(ExecutionStatus::Cancelled)
        ->and($state?->failureReason)->toBe('compensated on request')
        ->and($state?->stepStatuses)->toBe(['a' => StepStatus::Compensated, 'b' => StepStatus::Compensated]);
});

it('records a compensation on request before undoing anything, so recovery can tell it from a failure', function (): void {
    $engines = TestEngines::create();
    $log = [];
    compensatingWorkflow($engines, $log);
    $engines->workflows->start('w', [], [], 'w-1');

    $state = $engines->workflows->compensate('w-1');

    expect($state->status)->toBe(ExecutionStatus::Cancelled)
        ->and($state->variables[WorkflowEngine::COMPENSATION_REQUESTED] ?? null)->toBeTrue()
        ->and($log)->toBe(['run a', 'run b', 'run c', 'undo b', 'undo a']);
});

it('finishes the compensation of a failed step a crash interrupted and ends the workflow FAILED', function (): void {
    $engines = TestEngines::create(['recovery' => ['action' => 'resume', 'stale-threshold' => '1m']]);
    $log = [];
    compensatingWorkflow($engines, $log);
    $engines->persistence->save(ExecutionPersistenceContract::state('w-1', ExecutionPattern::Workflow, ExecutionStatus::Compensating, '2026-10-12T09:00:00+00:00', [
        'executionName' => 'w', 'stepStatuses' => ['a' => 'DONE', 'b' => 'COMPENSATED', 'c' => 'FAILED'], 'stepResults' => ['a' => 'a', 'b' => 'b'],
        'completionOrder' => ['a', 'b'], 'stepErrors' => ['c' => 'card declined'],
    ]));

    $report = $engines->recovery->recover();
    $state = $engines->persistence->findById('w-1');

    expect($report->resumed)->toBe(['w-1'])
        ->and($log)->toBe(['undo a'])
        ->and($state?->status)->toBe(ExecutionStatus::Failed)
        ->and($state?->failureReason)->toBe('card declined')
        ->and($state?->stepStatuses)->toBe(['a' => StepStatus::Compensated, 'b' => StepStatus::Compensated, 'c' => StepStatus::Failed])
        ->and($engines->deadLetterStore->findByCorrelationId('w-1'))->toHaveCount(1);
});

it('records a recovery that fails instead of aborting the sweep', function (): void {
    $engines = TestEngines::create(['recovery' => ['action' => 'resume', 'stale-threshold' => '1m']]);
    $engines->persistence->save(ExecutionPersistenceContract::state('ghost', ExecutionPattern::Saga, ExecutionStatus::Running, '2026-10-12T09:00:00+00:00', ['executionName' => 'unregistered']));

    $report = $engines->recovery->recover();

    expect($report->errors)->toHaveKey('ghost')
        ->and($report->errors['ghost'])->toContain('No saga [unregistered] is registered');
});

it('looks past a full batch of executions it cannot recover, so they never starve an older one it can', function (): void {
    $engines = TestEngines::create(['recovery' => ['action' => 'resume', 'stale-threshold' => '1m'], 'backpressure' => ['batch-size' => 2]]);
    $engines->workflowRegistry->register(WorkflowBuilder::workflow('w')->step('a')->handler(static fn (): string => 'a')->add()->build());
    $engines->persistence->save(ExecutionPersistenceContract::state('w-old', ExecutionPattern::Workflow, ExecutionStatus::Running, '2026-10-12T07:00:00+00:00', [
        'executionName' => 'w', 'stepStatuses' => ['a' => 'RUNNING'], 'stepResults' => [],
    ]));
    $engines->persistence->save(ExecutionPersistenceContract::state('ghost-1', ExecutionPattern::Saga, ExecutionStatus::Running, '2026-10-12T09:00:00+00:00', ['executionName' => 'unregistered']));
    $engines->persistence->save(ExecutionPersistenceContract::state('ghost-2', ExecutionPattern::Saga, ExecutionStatus::Running, '2026-10-12T09:30:00+00:00', ['executionName' => 'unregistered']));

    $report = $engines->recovery->recover();

    expect($report->stale)->toBe(['ghost-2', 'ghost-1', 'w-old'])
        ->and(array_keys($report->errors))->toBe(['ghost-2', 'ghost-1'])
        ->and($report->resumed)->toBe(['w-old'])
        ->and($engines->persistence->findById('w-old')?->status)->toBe(ExecutionStatus::Completed);
});

it('does nothing while recovery is disabled', function (): void {
    $engines = TestEngines::create(['recovery' => ['enabled' => false]]);
    $engines->persistence->save(ExecutionPersistenceContract::state('old', ExecutionPattern::Saga, ExecutionStatus::Completed, '2026-01-01T09:00:00+00:00'));

    expect($engines->recovery->recover()->toArray())->toBe(['stale' => [], 'resumed' => [], 'compensated' => [], 'errors' => [], 'cleaned' => 0, 'skipped' => []]);
});

it('leaves alone an execution that finished while the sweep waited for its lock, instead of undoing it', function (ExecutionPattern $pattern, ExecutionStatus $running, ExecutionStatus $finished): void {
    $persistence = new RacingExecutionPersistence;
    $engines = TestEngines::create(['recovery' => ['action' => 'compensate', 'stale-threshold' => '1m']], $persistence);
    $undone = [];
    $engines->sagaRegistry->register(SagaBuilder::saga('order')
        ->step('reserve')->handler(static fn (): string => 'h')->compensation(static function () use (&$undone): void {
            $undone[] = 'release';
        })->add()
        ->build());
    $engines->tccRegistry->register(TccBuilder::tcc('order')->retry(false)
        ->participant('reserve')->order(1)
        ->tryWith(static fn (): string => 'h')
        ->confirmWith(static function (): void {})
        ->cancelWith(static function () use (&$undone): void {
            $undone[] = 'cancel';
        })
        ->add()
        ->build());
    $selected = ExecutionPersistenceContract::state('x-1', $pattern, $running, '2026-10-12T09:00:00+00:00');
    $persistence->save($selected);
    // The live run finishes between findStale() and the sweep's reload under the execution lock.
    $persistence->changeOnNextFind(ExecutionPersistenceContract::state('x-1', $pattern, $finished, '2026-10-12T10:00:00+00:00', [
        'stepStatuses' => ['reserve' => $pattern === ExecutionPattern::Tcc ? 'CONFIRMED' : 'DONE'],
    ]));

    $report = $engines->recovery->recover();

    expect($report->skipped)->toBe(['x-1'])
        ->and($report->stale)->toBe([])
        ->and($report->compensated)->toBe([])
        ->and($report->errors)->toBe([])
        ->and($undone)->toBe([])
        ->and($persistence->findById('x-1')?->status)->toBe($finished);
})->with([
    'saga' => [ExecutionPattern::Saga, ExecutionStatus::Running, ExecutionStatus::Completed],
    'tcc' => [ExecutionPattern::Tcc, ExecutionStatus::Confirming, ExecutionStatus::Confirmed],
]);

it('leaves alone a workflow that checkpointed again while the sweep waited for its lock, instead of driving it twice', function (string $action): void {
    $persistence = new RacingExecutionPersistence;
    $engines = TestEngines::create(['recovery' => ['action' => $action, 'stale-threshold' => '1m']], $persistence);
    $log = [];
    compensatingWorkflow($engines, $log);
    $row = static fn (array $statuses): array => [
        'executionName' => 'w', 'stepStatuses' => $statuses, 'stepResults' => ['a' => 'a'], 'completionOrder' => ['a'],
    ];
    $persistence->save(ExecutionPersistenceContract::state('w-1', ExecutionPattern::Workflow, ExecutionStatus::Running, '2026-10-12T09:00:00+00:00', $row(['a' => 'DONE', 'b' => 'RUNNING'])));
    // Still RUNNING, but with a fresh checkpoint: the process driving it is alive.
    $persistence->changeOnNextFind(ExecutionPersistenceContract::state('w-1', ExecutionPattern::Workflow, ExecutionStatus::Running, '2026-10-12T09:59:30+00:00', $row(['a' => 'DONE', 'b' => 'DONE', 'c' => 'RUNNING'])));

    $report = $engines->recovery->recover();
    $state = $persistence->findById('w-1');

    expect($report->skipped)->toBe(['w-1'])
        ->and($report->stale)->toBe([])
        ->and($report->resumed)->toBe([])
        ->and($report->compensated)->toBe([])
        ->and($log)->toBe([])
        ->and($state?->status)->toBe(ExecutionStatus::Running)
        ->and($state === null ? null : ExecutionState::instant($state->updatedAt))->toBe(ExecutionState::instant(new DateTimeImmutable('2026-10-12T09:59:30+00:00')));
})->with(['resume', 'compensate']);

it('still recovers a stale execution the sweep finds unchanged under its lock', function (): void {
    $persistence = new RacingExecutionPersistence;
    $engines = TestEngines::create(['recovery' => ['action' => 'compensate', 'stale-threshold' => '1m']], $persistence);
    $log = [];
    compensatingWorkflow($engines, $log);
    $persistence->save(ExecutionPersistenceContract::state('w-1', ExecutionPattern::Workflow, ExecutionStatus::Running, '2026-10-12T09:00:00+00:00', [
        'executionName' => 'w', 'stepStatuses' => ['a' => 'DONE', 'b' => 'RUNNING'], 'stepResults' => ['a' => 'a'], 'completionOrder' => ['a'],
    ]));

    $report = $engines->recovery->recover();

    expect($report->compensated)->toBe(['w-1'])
        ->and($report->skipped)->toBe([])
        ->and($log)->toBe(['undo a'])
        ->and($persistence->findById('w-1')?->status)->toBe(ExecutionStatus::Cancelled);
});
