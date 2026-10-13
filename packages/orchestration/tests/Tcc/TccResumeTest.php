<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\Exception\InvalidExecutionStateException;
use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Core\StepStatus;
use Firefly\Orchestration\Core\TccPhase;
use Firefly\Orchestration\Persistence\ExecutionState;
use Firefly\Orchestration\Tcc\Builder\TccBuilder;
use Firefly\Orchestration\Tests\Support\RecordingExecutionPersistence;
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

it('resumes a CONFIRMING TCC with a CONFIRM_FAILED participant into CANCEL and ends FAILED', function (): void {
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
    // The process died between the CONFIRM_FAILED checkpoint and the switch to CANCEL (an engine that saved the
    // failure under CONFIRM): resuming into CONFIRM would confirm credit and report a false CONFIRMED.
    $engines->persistence->save(ExecutionState::fromArray([
        'correlationId' => 't-12', 'executionName' => 'transfer', 'pattern' => 'TCC', 'status' => 'CONFIRMING',
        'startedAt' => '2026-10-12T09:00:00.000000+00:00', 'updatedAt' => '2026-10-12T09:00:01.000000+00:00',
        'currentPhase' => 'CONFIRM',
        'stepStatuses' => ['debit' => 'CONFIRM_FAILED', 'credit' => 'DONE'],
        'stepErrors' => ['debit' => 'debit confirm refused'],
        'tryResults' => ['debit' => 'debit', 'credit' => 'credit'],
        'completionOrder' => ['debit', 'credit'],
    ]));

    $result = $engines->tccs->resume('t-12');

    expect($result->status)->toBe(ExecutionStatus::Failed)
        ->and($result->failedParticipantId)->toBe('debit')
        ->and($result->failedPhase)->toBe(TccPhase::Confirm)
        ->and($result->error?->getMessage())->toBe('debit confirm refused')
        ->and($log)->toBe(['cancel credit', 'cancel debit'])
        ->and($engines->persistence->findById('t-12')?->status)->toBe(ExecutionStatus::Failed)
        ->and($engines->deadLetterStore->findByCorrelationId('t-12'))->toHaveCount(1);
});

it('resumes a TRYING TCC with a FAILED required participant into CANCEL without trying it again', function (): void {
    $engines = TestEngines::create();
    $log = [];
    $phase = static function (string $name, string $id) use (&$log): Closure {
        return static function () use ($name, $id, &$log): string {
            $log[] = "{$name} {$id}";

            return $id;
        };
    };
    $engines->tccRegistry->register(TccBuilder::tcc('transfer')
        ->participant('audit')->order(0)->optional()->tryWith($phase('try', 'audit'))->confirmWith($phase('confirm', 'audit'))->cancelWith($phase('cancel', 'audit'))->add()
        ->participant('debit')->order(1)->tryWith($phase('try', 'debit'))->confirmWith($phase('confirm', 'debit'))->cancelWith($phase('cancel', 'debit'))->add()
        ->participant('credit')->order(2)->tryWith($phase('try', 'credit'))->confirmWith($phase('confirm', 'credit'))->cancelWith($phase('cancel', 'credit'))->add()
        ->participant('notify')->order(3)->tryWith($phase('try', 'notify'))->confirmWith($phase('confirm', 'notify'))->cancelWith($phase('cancel', 'notify'))->add()
        ->build());
    // credit used up its retries; the optional audit's earlier failure is not what the transaction cancels for.
    $engines->persistence->save(ExecutionState::fromArray([
        'correlationId' => 't-13', 'executionName' => 'transfer', 'pattern' => 'TCC', 'status' => 'TRYING',
        'startedAt' => '2026-10-12T09:00:00.000000+00:00', 'updatedAt' => '2026-10-12T09:00:01.000000+00:00',
        'currentPhase' => 'TRY',
        'stepStatuses' => ['audit' => 'FAILED', 'debit' => 'DONE', 'credit' => 'FAILED'],
        'stepErrors' => ['audit' => 'audit down', 'credit' => 'credit refused'],
        'tryResults' => ['debit' => 'debit'],
        'completionOrder' => ['debit'],
    ]));

    $result = $engines->tccs->resume('t-13');

    expect($result->isCanceled())->toBeTrue()
        ->and($result->failedParticipantId)->toBe('credit')
        ->and($result->failedPhase)->toBe(TccPhase::Try)
        ->and($result->error?->getMessage())->toBe('credit refused')
        ->and($log)->toBe(['cancel debit']);
});

it('checkpoints a required failure under CANCEL, so a crash right after it resumes into cancelling', function (string $phase, StepStatus $failed, ExecutionStatus $ending): void {
    $register = static function (TestEngines $engines, array &$log) use ($phase): void {
        $call = static function (string $name, string $id, bool $fails = false) use (&$log): Closure {
            return static function () use ($name, $id, $fails, &$log): string {
                $log[] = "{$name} {$id}";
                if ($fails) {
                    throw new RuntimeException("{$name} {$id} refused");
                }

                return $id;
            };
        };
        $engines->tccRegistry->register(TccBuilder::tcc('transfer')
            ->participant('debit')->order(1)->tryWith($call('try', 'debit'))->confirmWith($call('confirm', 'debit'))->cancelWith($call('cancel', 'debit'))->add()
            ->participant('credit')->order(2)->tryWith($call('try', 'credit', $phase === 'TRY'))->confirmWith($call('confirm', 'credit', $phase === 'CONFIRM'))->cancelWith($call('cancel', 'credit'))->add()
            ->build());
    };
    $recording = new RecordingExecutionPersistence;
    $first = TestEngines::create(persistence: $recording);
    $firstLog = [];
    $register($first, $firstLog);
    $first->tccs->execute('transfer', correlationId: 't-14');

    // The first checkpoint that records credit's failure is what a crash in the listeners' window leaves behind.
    $crashed = null;
    foreach ($recording->saved as $state) {
        if ($state->stepStatus('credit') === $failed) {
            $crashed = $state;
            break;
        }
    }
    expect($crashed?->currentPhase)->toBe(TccPhase::Cancel);

    $second = TestEngines::create();
    $log = [];
    $register($second, $log);
    $second->persistence->save($crashed ?? throw new LogicException('no failure checkpoint'));
    $result = $second->tccs->resume('t-14');

    expect($result->status)->toBe($ending)
        ->and($result->failedParticipantId)->toBe('credit')
        ->and($result->failedPhase?->value)->toBe($phase)
        ->and($log)->toBe($phase === 'TRY' ? ['cancel debit'] : ['cancel credit']); // a confirmed debit is not cancelled
})->with([
    'a try failure' => ['TRY', StepStatus::Failed, ExecutionStatus::Canceled],
    'a confirm failure' => ['CONFIRM', StepStatus::ConfirmFailed, ExecutionStatus::Failed],
]);

it('resumes a cancel on request interrupted mid-sweep as a cancel on request, confirmed participants included', function (): void {
    $register = static function (TestEngines $engines, array &$log, int &$errors): void {
        $call = static function (string $name, string $id) use (&$log): Closure {
            return static function () use ($name, $id, &$log): string {
                $log[] = "{$name} {$id}";

                return $id;
            };
        };
        $engines->tccRegistry->register(TccBuilder::tcc('transfer')
            ->participant('debit')->order(1)->tryWith($call('try', 'debit'))->confirmWith($call('confirm', 'debit'))->cancelWith($call('cancel', 'debit'))->add()
            ->participant('credit')->order(2)->tryWith($call('try', 'credit'))->confirmWith($call('confirm', 'credit'))->cancelWith($call('cancel', 'credit'))->add()
            ->onError(static function () use (&$errors): void {
                $errors++;
            })
            ->build());
    };
    $recording = new RecordingExecutionPersistence;
    $first = TestEngines::create(persistence: $recording);
    $firstLog = [];
    $firstErrors = 0;
    $register($first, $firstLog, $firstErrors);
    $first->tccs->execute('transfer', correlationId: 't-15');
    $first->tccs->compensate('t-15');

    // The checkpoint after credit's cancel landed and before debit's: a crash there leaves a CONFIRMED debit.
    $crashed = null;
    foreach ($recording->saved as $state) {
        if ($state->stepStatus('credit') === StepStatus::Canceled && $state->stepStatus('debit') === StepStatus::Confirmed) {
            $crashed = $state;
            break;
        }
    }
    expect($crashed?->status)->toBe(ExecutionStatus::Canceling)
        ->and($crashed?->currentPhase)->toBe(TccPhase::Cancel);

    $second = TestEngines::create();
    $log = [];
    $errors = 0;
    $register($second, $log, $errors);
    $second->persistence->save($crashed ?? throw new LogicException('no mid-sweep checkpoint'));
    $result = $second->tccs->resume('t-15');

    expect($result->status)->toBe(ExecutionStatus::Canceled)
        ->and($result->report?->failureReason)->toBe('canceled on request')
        ->and($log)->toBe(['cancel debit'])
        ->and($second->persistence->findById('t-15')?->stepStatus('debit'))->toBe(StepStatus::Canceled)
        ->and($second->deadLetterStore->findByCorrelationId('t-15'))->toBe([])
        ->and($errors)->toBe(0);
});

it('resumes a confirm failure whose failed participant was already cancelled as FAILED with its confirm error', function (): void {
    $register = static function (TestEngines $engines, array &$log): void {
        $call = static function (string $name, string $id, bool $fails = false) use (&$log): Closure {
            return static function () use ($name, $id, $fails, &$log): string {
                $log[] = "{$name} {$id}";
                if ($fails) {
                    throw new RuntimeException("{$name} {$id} refused");
                }

                return $id;
            };
        };
        $engines->tccRegistry->register(TccBuilder::tcc('transfer')->retry(false)
            ->participant('debit')->order(1)->tryWith($call('try', 'debit'))->confirmWith($call('confirm', 'debit'))->cancelWith($call('cancel', 'debit'))->add()
            ->participant('credit')->order(2)->tryWith($call('try', 'credit'))->confirmWith($call('confirm', 'credit', true))->cancelWith($call('cancel', 'credit'))->add()
            ->participant('notify')->order(3)->tryWith($call('try', 'notify'))->confirmWith($call('confirm', 'notify'))->cancelWith($call('cancel', 'notify'))->add()
            ->build());
    };
    $recording = new RecordingExecutionPersistence;
    $first = TestEngines::create(persistence: $recording);
    $firstLog = [];
    $register($first, $firstLog);
    $first->tccs->execute('transfer', correlationId: 't-16');

    // The sweep cancels notify, then the CONFIRM_FAILED credit, overwriting its status with CANCELED; a crash
    // right after that checkpoint must not lose the confirm failure.
    $crashed = null;
    foreach ($recording->saved as $state) {
        if ($state->status === ExecutionStatus::Canceling && $state->stepStatus('credit') === StepStatus::Canceled) {
            $crashed = $state;
            break;
        }
    }

    $second = TestEngines::create();
    $log = [];
    $register($second, $log);
    $second->persistence->save($crashed ?? throw new LogicException('no mid-sweep checkpoint'));
    $result = $second->tccs->resume('t-16');

    expect($result->status)->toBe(ExecutionStatus::Failed)
        ->and($result->failedParticipantId)->toBe('credit')
        ->and($result->failedPhase)->toBe(TccPhase::Confirm)
        ->and($result->error?->getMessage())->toBe('confirm credit refused')
        ->and($log)->toBe([])
        ->and($second->persistence->findById('t-16')?->stepStatus('debit'))->toBe(StepStatus::Confirmed)
        ->and($second->deadLetterStore->findByCorrelationId('t-16'))->toHaveCount(1);
});

it('compensates a cancel on request that ended FAILED again, retrying only the cancels that failed', function (): void {
    $engines = TestEngines::create();
    $log = [];
    $creditFails = 1;
    $engines->tccRegistry->register(TccBuilder::tcc('transfer')->retry(false)
        ->participant('debit')->order(1)->tryWith(static fn (): string => 'debit')->confirmWith(static fn (): null => null)
        ->cancelWith(static function () use (&$log): void {
            $log[] = 'cancel debit';
        })->add()
        ->participant('credit')->order(2)->tryWith(static fn (): string => 'credit')->confirmWith(static fn (): null => null)
        ->cancelWith(static function () use (&$log, &$creditFails): void {
            $log[] = 'cancel credit';
            if ($creditFails-- > 0) {
                throw new RuntimeException('ledger offline');
            }
        })->add()
        ->build());
    $engines->tccs->execute('transfer', correlationId: 't-17');

    $failed = $engines->tccs->compensate('t-17');
    $retried = $engines->tccs->compensate('t-17');

    expect($failed->status)->toBe(ExecutionStatus::Failed)
        ->and($retried->status)->toBe(ExecutionStatus::Canceled)
        ->and($log)->toBe(['cancel credit', 'cancel debit', 'cancel credit'])
        ->and($engines->deadLetterStore->findByCorrelationId('t-17'))->toBe([]);
});

it('does not reopen a TCC that FAILED on a confirm failure', function (): void {
    $engines = TestEngines::create();
    $engines->tccRegistry->register(TccBuilder::tcc('transfer')->retry(false)
        ->participant('debit')->order(1)->tryWith(static fn (): string => 'debit')->confirmWith(static fn (): null => null)->cancelWith(static fn (): null => null)->add()
        ->participant('credit')->order(2)->tryWith(static fn (): string => 'credit')
        ->confirmWith(static function (): void {
            throw new RuntimeException('credit confirm refused');
        })
        ->cancelWith(static function (): void {
            throw new RuntimeException('credit cancel refused');
        })->add()
        ->build());

    expect($engines->tccs->execute('transfer', correlationId: 't-18')->status)->toBe(ExecutionStatus::Failed)
        ->and(fn () => $engines->tccs->compensate('t-18'))->toThrow(InvalidExecutionStateException::class, 'already FAILED');
});
