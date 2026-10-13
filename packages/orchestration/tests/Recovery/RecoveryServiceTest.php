<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Tests\Support\ExecutionPersistenceContract;
use Firefly\Orchestration\Tests\Support\TestEngines;
use Firefly\Orchestration\Workflow\Builder\WorkflowBuilder;

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

    expect($engines->recovery->recover()->toArray())->toBe(['stale' => [], 'resumed' => [], 'compensated' => [], 'errors' => [], 'cleaned' => 0]);
});
