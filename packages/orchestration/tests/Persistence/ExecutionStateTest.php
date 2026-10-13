<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Core\StepStatus;
use Firefly\Orchestration\Core\TccPhase;
use Firefly\Orchestration\Persistence\ExecutionState;
use Firefly\Orchestration\Persistence\StateSerializer;

function orchestrationFullState(): ExecutionState
{
    $at = new DateTimeImmutable('2026-10-12T10:00:00+00:00');

    return new ExecutionState(
        correlationId: 'c-1',
        executionName: 'order',
        pattern: ExecutionPattern::Workflow,
        status: ExecutionStatus::Waiting,
        startedAt: $at,
        updatedAt: $at->modify('+1 second'),
        input: ['orderId' => 7],
        headers: ['tenant' => 'acme'],
        variables: ['tier' => 'gold'],
        stepResults: ['reserve' => ['ok' => true]],
        stepStatuses: ['reserve' => StepStatus::Done, 'approve' => StepStatus::Waiting],
        stepAttempts: ['reserve' => 2],
        stepLatenciesMs: ['reserve' => 15],
        stepInputs: ['reserve' => ['orderId' => 7]],
        completionOrder: ['reserve'],
        compensationResults: ['x' => 1],
        compensationErrors: ['y' => 'boom'],
        stepErrors: ['z' => 'bad'],
        tryResults: ['debit' => 'hold-1'],
        currentPhase: TccPhase::Confirm,
        idempotencyKeys: ['k-1'],
        topologyLayers: [['reserve'], ['approve']],
        failureReason: null,
        completedAt: null,
        wakeAt: $at->modify('+1 hour'),
        searchAttributes: ['customer' => 'ada', 'vip' => true],
        signals: ['approval' => ['by' => 'grace']],
        timers: ['approve:timer:0' => '2026-10-12T11:00:00.000000+00:00'],
        waitingOn: 'approve',
        parentCorrelationId: 'p-1',
        children: ['spawn' => 'child-1'],
        report: ['status' => 'WAITING'],
    );
}

it('round-trips every field through toArray, JSON and fromArray', function (): void {
    $state = orchestrationFullState();

    $restored = StateSerializer::decode(StateSerializer::encode($state));

    expect($restored->toArray())->toBe($state->toArray())
        ->and($restored->stepStatus('approve'))->toBe(StepStatus::Waiting)
        ->and($restored->stepStatus('never'))->toBe(StepStatus::Pending)
        ->and($restored->currentPhase)->toBe(TccPhase::Confirm)
        ->and($restored->wakeAt?->format('H:i'))->toBe('11:00');
});

it('changes status and the update instant without touching the rest', function (): void {
    $state = orchestrationFullState();
    $done = $state->withStatus(ExecutionStatus::Completed, new DateTimeImmutable('2026-10-12T12:00:00+00:00'));

    expect($done->status)->toBe(ExecutionStatus::Completed)
        ->and($done->isTerminal())->toBeTrue()
        ->and(ExecutionState::instant($done->updatedAt))->toBe('2026-10-12T12:00:00.000000+00:00')
        ->and($done->completedAt)->not->toBeNull()
        ->and($done->stepResults)->toBe($state->stepResults);
});

it('compares search attribute values in one canonical string form', function (): void {
    expect(ExecutionState::attributeValue(true))->toBe('true')
        ->and(ExecutionState::attributeValue(3))->toBe('3')
        ->and(ExecutionState::attributeValue(1.5))->toBe('1.5')
        ->and(ExecutionState::attributeValue('x'))->toBe('x');
});
