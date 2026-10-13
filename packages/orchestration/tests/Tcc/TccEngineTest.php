<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\Exception\InvalidExecutionStateException;
use Firefly\Orchestration\Core\Exception\PatternDisabledException;
use Firefly\Orchestration\Core\ExecutionContext;
use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Core\StepStatus;
use Firefly\Orchestration\Core\TccPhase;
use Firefly\Orchestration\Tcc\Builder\TccBuilder;
use Firefly\Orchestration\Tcc\Registry\TccDefinition;
use Firefly\Orchestration\Tests\Support\TestEngines;

/**
 * debit (order 1) and credit (order 2); `$fail` names "<participant>:<phase>" that throw (`times` left, PHP_INT_MAX = always).
 *
 * @param  list<string>  $log
 * @param  array<string, int>  $fail
 */
function orchestrationTransfer(array &$log, array $fail = [], bool $optionalCredit = false, int $confirmRetry = -1): TccDefinition
{
    $builder = TccBuilder::tcc('transfer')->retry(true, 0, 1);
    foreach (['debit' => 1, 'credit' => 2] as $id => $order) {
        $phase = static function (string $name) use ($id, &$log, &$fail): Closure {
            return static function (mixed $implicit, ExecutionContext $ctx) use ($id, $name, &$log, &$fail): string {
                $log[] = "{$name} {$id}".($name === 'try' ? '' : ' ('.(is_string($implicit) ? $implicit : '?').')');
                if (($fail["{$id}:{$name}"] ?? 0) > 0) {
                    $fail["{$id}:{$name}"]--;

                    throw new RuntimeException("{$name} {$id} failed");
                }

                return "{$id}-{$name}";
            };
        };
        $builder = $builder->participant($id)->order($order)->optional($optionalCredit && $id === 'credit')
            ->tryWith($phase('try'))
            ->confirmWith($phase('confirm'), retry: $confirmRetry)
            ->cancelWith($phase('cancel'))
            ->event('ledger', "ledger.{$id}", 'account')
            ->add();
    }

    return $builder->build();
}

it('tries every participant in order, then confirms them in order', function (): void {
    $engines = TestEngines::create();
    $log = [];

    $result = $engines->tccs->execute(orchestrationTransfer($log), ['account' => 'acc-1'], correlationId: 't-1');

    expect($result->isConfirmed())->toBeTrue()
        ->and($result->status)->toBe(ExecutionStatus::Confirmed)
        ->and($log)->toBe(['try debit', 'try credit', 'confirm debit (debit-try)', 'confirm credit (credit-try)'])
        ->and($result->tryResultOf('credit'))->toBe('credit-try')
        ->and($result->participants['debit']->status)->toBe(StepStatus::Confirmed)
        ->and($engines->persistence->findById('t-1')?->status)->toBe(ExecutionStatus::Confirmed)
        ->and(array_column($engines->eda->published, 'eventType'))->toBe(['ledger.debit', 'ledger.credit'])
        ->and($engines->eda->published[0]['headers']['key'] ?? null)->toBe('acc-1')
        ->and($engines->events->hooks())->toContain('onPhaseStarted', 'onPhaseCompleted', 'onParticipantSuccess');
});

it('cancels the tried participants in reverse when a try fails', function (): void {
    $engines = TestEngines::create();
    $log = [];

    $result = $engines->tccs->execute(orchestrationTransfer($log, ['credit:try' => PHP_INT_MAX]), correlationId: 't-2');

    expect($result->isCanceled())->toBeTrue()
        ->and($result->failedParticipantId)->toBe('credit')
        ->and($result->failedPhase)->toBe(TccPhase::Try)
        ->and($result->error?->getMessage())->toBe('try credit failed')
        ->and($log)->toBe(['try debit', 'try credit', 'cancel debit (debit-try)'])
        ->and($result->participants['debit']->status)->toBe(StepStatus::Canceled)
        ->and($engines->deadLetterStore->findByCorrelationId('t-2')[0]->stepId ?? null)->toBe('credit');
});

it('cancels what it could not confirm and ends FAILED when a confirm fails', function (): void {
    $engines = TestEngines::create();
    $log = [];

    $result = $engines->tccs->execute(orchestrationTransfer($log, ['credit:confirm' => PHP_INT_MAX]));

    expect($result->isFailed())->toBeTrue()
        ->and($result->failedPhase)->toBe(TccPhase::Confirm)
        ->and($log)->toBe(['try debit', 'try credit', 'confirm debit (debit-try)', 'confirm credit (credit-try)', 'cancel credit (credit-try)'])
        ->and($result->participants['debit']->status)->toBe(StepStatus::Confirmed)
        ->and($result->participants['credit']->status)->toBe(StepStatus::Canceled);
});

it('lets an optional participant fail its try without cancelling the others', function (): void {
    $engines = TestEngines::create();
    $log = [];

    $result = $engines->tccs->execute(orchestrationTransfer($log, ['credit:try' => PHP_INT_MAX], optionalCredit: true));

    expect($result->isConfirmed())->toBeTrue()
        ->and($log)->toBe(['try debit', 'try credit', 'confirm debit (debit-try)'])
        ->and($result->participants['credit']->status)->toBe(StepStatus::Failed);
});

it('retries a phase with its own retry count', function (): void {
    $engines = TestEngines::create();
    $log = [];

    $result = $engines->tccs->execute(orchestrationTransfer($log, ['credit:confirm' => 1], confirmRetry: 1));

    expect($result->isConfirmed())->toBeTrue()
        ->and($result->participants['credit']->attempts)->toBe(1);
});

it('refuses executions while TCC is disabled', function (): void {
    $log = [];
    TestEngines::create(['tcc' => ['enabled' => false]])->tccs->execute(orchestrationTransfer($log));
})->throws(PatternDisabledException::class, 'firefly.orchestration.tcc.enabled');

it('refuses to start a TCC under a correlation id that already exists', function (): void {
    $engines = TestEngines::create();
    $log = [];
    $engines->tccs->execute(orchestrationTransfer($log), correlationId: 't-dup');

    expect(fn () => $engines->tccs->execute(orchestrationTransfer($log), correlationId: 't-dup'))
        ->toThrow(InvalidExecutionStateException::class, 'Execution [t-dup] already exists (TCC transfer, CONFIRMED)')
        ->and($log)->toHaveCount(4);
});

it('calls OnTccComplete after a confirm and OnTccError after a cancel, and suppressError reports a success', function (): void {
    $engines = TestEngines::create();
    $calls = [];
    $tcc = static function (bool $fails, bool $suppress) use (&$calls): TccDefinition {
        return TccBuilder::tcc($fails ? 'failing' : 'fine')->retry(false)
            ->participant('p')
            ->tryWith(static function () use ($fails): string {
                if ($fails) {
                    throw new RuntimeException('no stock');
                }

                return 'held';
            })
            ->confirmWith(static fn (): null => null)
            ->cancelWith(static fn (): null => null)
            ->add()
            ->onComplete(static function (ExecutionContext $ctx) use (&$calls): void {
                $calls[] = "complete {$ctx->executionName}";
            })
            ->onError(static function (ExecutionContext $ctx) use (&$calls): void {
                $calls[] = "error {$ctx->executionName}";
            }, suppressError: $suppress)
            ->build();
    };

    $confirmed = $engines->tccs->execute($tcc(false, false));
    $canceled = $engines->tccs->execute($tcc(true, false));
    $suppressed = TestEngines::create()->tccs->execute($tcc(true, true));

    expect($confirmed->isSuccess())->toBeTrue()
        ->and($canceled->isSuccess())->toBeFalse()
        ->and($canceled->isCanceled())->toBeTrue()
        ->and($suppressed->isCanceled())->toBeTrue()
        ->and($suppressed->isSuccess())->toBeTrue()
        ->and($calls)->toBe(['complete fine', 'error failing', 'error failing']);
});

it('ends FAILED and reports the participant whose cancel failed', function (): void {
    $engines = TestEngines::create();
    $log = [];

    $result = $engines->tccs->execute(orchestrationTransfer($log, ['credit:try' => PHP_INT_MAX, 'debit:cancel' => PHP_INT_MAX]), correlationId: 't-3');

    expect($result->isFailed())->toBeTrue()
        ->and($result->failedParticipantId)->toBe('credit')
        ->and($result->failedPhase)->toBe(TccPhase::Try)
        ->and($result->participants['debit']->status)->toBe(StepStatus::CancelFailed)
        ->and($engines->persistence->findById('t-3')?->compensationErrors)->toBe(['debit' => 'cancel debit failed'])
        ->and($engines->events->hooks())->toContain('onPhaseFailed');
});

/**
 * debit (order 1) and credit (order 2); credit's try reserves, then overruns its 10 ms deadline — a sub-second
 * deadline is only checked after the call returns, so every attempt has reserved by the time it times out.
 *
 * @param  list<string>  $log
 */
function orchestrationSlowCredit(array &$log, bool $optionalCredit = false, int $maxRetries = 1): TccDefinition
{
    $call = static function (string $name, string $id, bool $slow = false) use (&$log): Closure {
        return static function (mixed $implicit) use ($name, $id, $slow, &$log): string {
            $log[] = "{$name} {$id}".($name === 'cancel' ? ' ('.(is_string($implicit) ? $implicit : 'null').')' : '');
            if ($slow) {
                usleep(30_000);
            }

            return "{$id}-{$name}";
        };
    };

    return TccBuilder::tcc('transfer')->retry(true, $maxRetries, 0)
        ->participant('debit')->order(1)->tryWith($call('try', 'debit'))->confirmWith($call('confirm', 'debit'))->cancelWith($call('cancel', 'debit'))->add()
        ->participant('credit')->order(2)->optional($optionalCredit)->tryWith($call('try', 'credit', slow: true), timeoutMs: 10)->confirmWith($call('confirm', 'credit'))->cancelWith($call('cancel', 'credit'))->add()
        ->build();
}

it('cancels a required participant whose try timed out after reserving, with a null try result', function (): void {
    $engines = TestEngines::create();
    $log = [];

    $result = $engines->tccs->execute(orchestrationSlowCredit($log), correlationId: 't-timeout');

    expect($result->isCanceled())->toBeTrue()
        ->and($result->failedParticipantId)->toBe('credit')
        ->and($result->failedPhase)->toBe(TccPhase::Try)
        // The first attempt and its retry both reserved; the cancel releases what credit holds, then debit's.
        ->and($log)->toBe(['try debit', 'try credit', 'try credit', 'cancel credit (null)', 'cancel debit (debit-try)'])
        ->and($result->participants['credit']->status)->toBe(StepStatus::Canceled)
        ->and($result->participants['debit']->status)->toBe(StepStatus::Canceled)
        ->and($engines->persistence->findById('t-timeout')?->status)->toBe(ExecutionStatus::Canceled);
});

it('cancels an optional participant whose try timed out once the others confirm, and stays CONFIRMED', function (): void {
    $engines = TestEngines::create();
    $log = [];

    $result = $engines->tccs->execute(orchestrationSlowCredit($log, optionalCredit: true, maxRetries: 0), correlationId: 't-opt-timeout');

    expect($result->isConfirmed())->toBeTrue()
        ->and($log)->toBe(['try debit', 'try credit', 'confirm debit', 'cancel credit (null)'])
        ->and($result->participants['debit']->status)->toBe(StepStatus::Confirmed)
        ->and($result->participants['credit']->status)->toBe(StepStatus::Canceled)
        ->and($engines->persistence->findById('t-opt-timeout')?->status)->toBe(ExecutionStatus::Confirmed);
});
