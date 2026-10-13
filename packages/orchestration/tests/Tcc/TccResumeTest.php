<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\Exception\InvalidExecutionStateException;
use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Core\TccPhase;
use Firefly\Orchestration\Persistence\ExecutionState;
use Firefly\Orchestration\Tcc\Builder\TccBuilder;
use Firefly\Orchestration\Tests\Support\TestEngines;

it('resumes a TCC interrupted in CONFIRMING and confirms only what is left', function (): void {
    $engines = TestEngines::create();
    $log = [];
    // A plain closure, not an arrow function: an arrow function captures $log by value, so the phases would
    // append to its copy and the test would never see them.
    $phase = static function (string $name, string $id) use (&$log): Closure {
        return static function (mixed $implicit) use ($name, $id, &$log): string {
            $log[] = "{$name} {$id}";

            return "{$id}-{$name}";
        };
    };
    $engines->tccRegistry->register(TccBuilder::tcc('transfer')
        ->participant('debit')->order(1)->tryWith($phase('try', 'debit'))->confirmWith($phase('confirm', 'debit'))->cancelWith($phase('cancel', 'debit'))->add()
        ->participant('credit')->order(2)->tryWith($phase('try', 'credit'))->confirmWith($phase('confirm', 'credit'))->cancelWith($phase('cancel', 'credit'))->add()
        ->build());
    $engines->persistence->save(ExecutionState::fromArray([
        'correlationId' => 't-9', 'executionName' => 'transfer', 'pattern' => 'TCC', 'status' => 'CONFIRMING',
        'startedAt' => '2026-10-12T09:00:00.000000+00:00', 'updatedAt' => '2026-10-12T09:00:01.000000+00:00',
        'currentPhase' => 'CONFIRM',
        'stepStatuses' => ['debit' => 'CONFIRMED', 'credit' => 'DONE'],
        'tryResults' => ['debit' => 'debit-try', 'credit' => 'credit-try'],
        'stepResults' => ['debit' => 'debit-try', 'credit' => 'credit-try'],
        'completionOrder' => ['debit', 'credit'],
    ]));

    $result = $engines->tccs->resume('t-9');

    expect($result->isConfirmed())->toBeTrue()
        ->and($log)->toBe(['confirm credit'])
        ->and($engines->persistence->findById('t-9')?->status)->toBe(ExecutionStatus::Confirmed);
});

it('cancels every tried participant of a confirmed TCC on request', function (): void {
    $engines = TestEngines::create();
    $log = [];
    $phase = static function (string $name, string $id) use (&$log): Closure {
        return static function () use ($name, $id, &$log): string {
            $log[] = "{$name} {$id}";

            return $id;
        };
    };
    $engines->tccRegistry->register(TccBuilder::tcc('transfer')
        ->participant('debit')->order(1)->tryWith($phase('try', 'debit'))->confirmWith($phase('confirm', 'debit'))->cancelWith($phase('cancel', 'debit'))->add()
        ->participant('credit')->order(2)->tryWith($phase('try', 'credit'))->confirmWith($phase('confirm', 'credit'))->cancelWith($phase('cancel', 'credit'))->add()
        ->build());
    $engines->tccs->execute('transfer', correlationId: 't-1');

    $canceled = $engines->tccs->compensate('t-1');

    expect($canceled->status)->toBe(ExecutionStatus::Canceled)
        ->and(array_slice($log, 4))->toBe(['cancel credit', 'cancel debit']);
});

it('resumes a TCC interrupted in CANCELING, cancels what is left and reports the recorded failure', function (): void {
    $engines = TestEngines::create();
    $log = [];
    $phase = static function (string $name, string $id) use (&$log): Closure {
        return static function () use ($name, $id, &$log): string {
            $log[] = "{$name} {$id}";

            return $id;
        };
    };
    $engines->tccRegistry->register(TccBuilder::tcc('transfer')
        ->participant('debit')->order(1)->tryWith($phase('try', 'debit'))->confirmWith($phase('confirm', 'debit'))->cancelWith($phase('cancel', 'debit'))->add()
        ->participant('credit')->order(2)->tryWith($phase('try', 'credit'))->confirmWith($phase('confirm', 'credit'))->cancelWith($phase('cancel', 'credit'))->add()
        ->build());
    $engines->persistence->save(ExecutionState::fromArray([
        'correlationId' => 't-10', 'executionName' => 'transfer', 'pattern' => 'TCC', 'status' => 'CANCELING',
        'startedAt' => '2026-10-12T09:00:00.000000+00:00', 'updatedAt' => '2026-10-12T09:00:01.000000+00:00',
        'currentPhase' => 'CANCEL',
        'stepStatuses' => ['debit' => 'DONE', 'credit' => 'FAILED'],
        'stepErrors' => ['credit' => 'credit refused'],
        'tryResults' => ['debit' => 'debit'],
        'completionOrder' => ['debit'],
    ]));

    $result = $engines->tccs->resume('t-10');

    expect($result->isCanceled())->toBeTrue()
        ->and($result->failedParticipantId)->toBe('credit')
        ->and($result->failedPhase)->toBe(TccPhase::Try)
        ->and($result->error?->getMessage())->toBe('credit refused')
        ->and($log)->toBe(['cancel debit'])
        ->and($engines->deadLetterStore->findByCorrelationId('t-10'))->toHaveCount(1);
});

it('neither dead-letters nor calls OnTccError when a TCC is cancelled on request', function (): void {
    $engines = TestEngines::create();
    $errors = 0;
    $engines->tccRegistry->register(TccBuilder::tcc('hold')
        ->participant('p')->tryWith(static fn (): string => 'held')->confirmWith(static fn (): null => null)->cancelWith(static fn (): null => null)->add()
        ->onError(static function () use (&$errors): void {
            $errors++;
        })
        ->build());
    $engines->tccs->execute('hold', correlationId: 't-11');

    $canceled = $engines->tccs->compensate('t-11');

    expect($canceled->isCanceled())->toBeTrue()
        ->and($canceled->report?->failureReason)->toBe('canceled on request')
        ->and($errors)->toBe(0)
        ->and($engines->deadLetterStore->findByCorrelationId('t-11'))->toBe([])
        ->and(fn () => $engines->tccs->compensate('t-11'))->toThrow(InvalidExecutionStateException::class, 'already CANCELED');
});
