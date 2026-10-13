<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\ExecutionContext;
use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Core\StepStatus;
use Firefly\Orchestration\Core\TccPhase;
use Firefly\Orchestration\Persistence\ExecutionState;

it('starts with a minted correlation id, or the one it is given', function (): void {
    $now = new DateTimeImmutable('2026-10-12T10:00:00+00:00');

    expect(ExecutionContext::start(ExecutionPattern::Saga, 's', [], [], null, $now)->correlationId)->toMatch('/^[0-9a-f-]{36}$/')
        ->and(ExecutionContext::start(ExecutionPattern::Saga, 's', [], [], 'given', $now)->correlationId)->toBe('given');
});

it('survives a checkpoint: everything an engine needs to resume comes back from the state', function (): void {
    $now = new DateTimeImmutable('2026-10-12T10:00:00+00:00');
    $ctx = ExecutionContext::start(ExecutionPattern::Workflow, 'approval', ['orderId' => 9], ['tenant' => 'acme'], 'c-9', $now);
    $ctx->putStepInput('reserve', ['orderId' => 9]);
    $ctx->setStepStatus('reserve', StepStatus::Done);
    $ctx->recordAttempt('reserve');
    $ctx->recordAttempt('reserve');
    $ctx->setLatencyMs('reserve', 12);
    $ctx->putResult('reserve', new ArrayObject(['hold' => 'h-1']));
    $ctx->markCompleted('reserve');
    $ctx->markCompleted('reserve');
    $ctx->putStepError('charge', new RuntimeException('card declined'));
    $ctx->putCompensationError('reserve', new RuntimeException('undo failed'));
    $ctx->putTryResult('debit', 'hold');
    $ctx->setCurrentPhase(TccPhase::Try);
    $ctx->addIdempotencyKey('order-9');
    $ctx->setTopologyLayers([['reserve'], ['charge']]);
    $ctx->setSearchAttribute('customer', 'ada');
    $ctx->receiveSignal('approval', ['by' => 'grace']);
    $ctx->scheduleTimer('t-1', $now->modify('+10 minutes'));
    $ctx->scheduleTimer('t-2', $now->modify('+5 minutes'));
    $ctx->setWaitingOn('approve');
    $ctx->setParentCorrelationId('p-1');
    $ctx->setChild('spawn', 'child-1');
    $ctx->setVariable('tier', 'gold');

    $state = $ctx->toState(ExecutionStatus::Waiting, $now->modify('+1 second'));
    $back = ExecutionContext::fromState($state);

    expect($state->stepResults)->toBe(['reserve' => ['hold' => 'h-1']])
        ->and($state->completionOrder)->toBe(['reserve'])
        ->and($state->stepErrors)->toBe(['charge' => 'card declined'])
        ->and(ExecutionState::instant($state->wakeAt ?? $now))->toBe('2026-10-12T10:05:00.000000+00:00')
        ->and($back->correlationId)->toBe('c-9')
        ->and($back->input())->toBe(['orderId' => 9])
        ->and($back->header('tenant'))->toBe('acme')
        ->and($back->attempts('reserve'))->toBe(2)
        ->and($back->stepStatus('reserve'))->toBe(StepStatus::Done)
        ->and($back->stepError('charge')?->getMessage())->toBe('card declined')
        ->and($back->compensationError('reserve')?->getMessage())->toBe('undo failed')
        ->and($back->tryResult('debit'))->toBe('hold')
        ->and($back->currentPhase())->toBe(TccPhase::Try)
        ->and($back->hasIdempotencyKey('order-9'))->toBeTrue()
        ->and($back->consumeSignal('approval'))->toBe(['by' => 'grace'])
        ->and($back->hasSignal('approval'))->toBeFalse()
        ->and($back->timerFired('t-2', $now->modify('+5 minutes')))->toBeTrue()
        ->and($back->timerFired('t-1', $now->modify('+5 minutes')))->toBeFalse()
        ->and($back->waitingOn())->toBe('approve')
        ->and($back->parentCorrelationId())->toBe('p-1')
        ->and($back->child('spawn'))->toBe('child-1')
        ->and($back->variable('tier'))->toBe('gold');
});

it('includes the workflow deadline in the wake-up instant while waiting, and clears it once running', function (): void {
    $now = new DateTimeImmutable('2026-10-12T10:00:00+00:00');
    $ctx = ExecutionContext::start(ExecutionPattern::Workflow, 'w', [], [], 'c', $now);
    $ctx->scheduleTimer('t', $now->modify('+1 hour'));
    $ctx->setDeadline($now->modify('+30 minutes'));

    expect(ExecutionState::instant($ctx->toState(ExecutionStatus::Waiting, $now)->wakeAt ?? $now))->toBe('2026-10-12T10:30:00.000000+00:00')
        ->and($ctx->toState(ExecutionStatus::Running, $now)->wakeAt)->toBeNull();
});
