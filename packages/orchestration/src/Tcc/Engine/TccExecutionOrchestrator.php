<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tcc\Engine;

use Firefly\Kernel\Exception\Infrastructure\TimeoutException;
use Firefly\Orchestration\Core\EngineSupport;
use Firefly\Orchestration\Core\Exception\OrchestrationException;
use Firefly\Orchestration\Core\ExecutionContext;
use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Core\StepInvoker;
use Firefly\Orchestration\Core\StepOptions;
use Firefly\Orchestration\Core\StepStatus;
use Firefly\Orchestration\Core\TccPhase;
use Firefly\Orchestration\Event\OrchestrationEvent;
use Firefly\Orchestration\Saga\Engine\SagaExecutionOrchestrator;
use Firefly\Orchestration\Settings\TccSettings;
use Firefly\Orchestration\Tcc\Registry\TccDefinition;
use Firefly\Orchestration\Tcc\Registry\TccParticipantDefinition;
use Throwable;

/**
 * Drives the three TCC phases (jafly TccExecutionOrchestrator), continuing from the checkpointed phase:
 *
 *  - TRY: participants in order; a required participant's failure ends TRY and goes to CANCEL; an optional
 *    one's failure is recorded and TRY continues. A participant already DONE (resume) is not tried again.
 *  - CONFIRM: every DONE participant in order, with its try result; a failure goes to CANCEL with the TCC
 *    ending FAILED (some participants already confirmed — jafly parity).
 *  - CANCEL: every tried-but-unconfirmed participant in REVERSE order; a cancel failure ends FAILED.
 *
 * The phase is checkpointed as it starts and every participant result as it lands, so resume() picks up a
 * CONFIRMING or CANCELING transaction exactly where a crash left it — which is why confirm and cancel must be
 * idempotent. A required failure is checkpointed together with the switch to CANCEL, and a resumed state that
 * still holds one goes straight to CANCEL, so a resume never retries an exhausted participant or reports a
 * transaction with a CONFIRM_FAILED participant as CONFIRMED.
 *
 * A cancel on request (cancelAll(), behind TccEngine::compensate()) is recorded in the context as the
 * CANCEL_REQUESTED variable before its first CANCELING checkpoint. Without it a crash in the middle of undoing a
 * CONFIRMED transaction would resume into the failure-driven CANCEL, which leaves confirmed participants
 * confirmed, and report a clean rollback over resources that are still committed. TccEngine::resume() reads it
 * through cancelRequested() and finishes such a transaction as the cancel on request it was.
 */
final class TccExecutionOrchestrator
{
    /** The context variable that marks a cancel on request; reserved, an application never sets it. */
    public const string CANCEL_REQUESTED = 'firefly.tcc.cancel-requested';

    public function __construct(
        private readonly StepInvoker $invoker,
        private readonly EngineSupport $support,
        private readonly TccSettings $settings,
    ) {}

    public function orchestrate(TccDefinition $tcc, TccInputs $inputs, ExecutionContext $context): TccRunOutcome
    {
        $phase = $context->currentPhase() ?? TccPhase::Try;

        // A state checkpointed before the switch to CANCEL was saved (or by an older engine) can still say TRY or
        // CONFIRM while already holding a required failure; running that phase again would retry an exhausted
        // participant or skip a CONFIRM_FAILED one and report CONFIRMED. Such a transaction is cancelling.
        if ($phase !== TccPhase::Cancel && $this->recordedFailure($tcc, $context)[0] !== null) {
            $phase = TccPhase::Cancel;
        }

        if ($phase === TccPhase::Try) {
            $failure = $this->tryPhase($tcc, $inputs, $context);
            if ($failure !== null) {
                return $this->cancelPhase($tcc, $context, $failure[0], TccPhase::Try, $failure[1], includeConfirmed: false);
            }
            $phase = TccPhase::Confirm;
        }

        if ($phase === TccPhase::Confirm) {
            $failure = $this->confirmPhase($tcc, $context);
            if ($failure !== null) {
                return $this->cancelPhase($tcc, $context, $failure[0], TccPhase::Confirm, $failure[1], includeConfirmed: false);
            }

            return new TccRunOutcome(ExecutionStatus::Confirmed);
        }

        [$failedId, $failedPhase, $error] = $this->recordedFailure($tcc, $context);

        return $this->cancelPhase($tcc, $context, $failedId, $failedPhase, $error, includeConfirmed: false);
    }

    public function cancelAll(TccDefinition $tcc, ExecutionContext $context): TccRunOutcome
    {
        // Set before cancelPhase() saves its first CANCELING checkpoint, so every checkpoint of this sweep says
        // it is a cancel on request and a resume after a crash cancels the confirmed participants too.
        $context->setVariable(self::CANCEL_REQUESTED, true);

        return $this->cancelPhase($tcc, $context, null, null, null, includeConfirmed: true);
    }

    /** Whether this transaction is (or was) being cancelled on request, by cancelAll(). */
    public function cancelRequested(ExecutionContext $context): bool
    {
        return $context->variable(self::CANCEL_REQUESTED) === true;
    }

    /** @return array{0: string, 1: Throwable}|null the failed required participant and its error */
    private function tryPhase(TccDefinition $tcc, TccInputs $inputs, ExecutionContext $context): ?array
    {
        $context->setCurrentPhase(TccPhase::Try);
        $this->support->events->onPhaseStarted($tcc->name, $context->correlationId, TccPhase::Try);
        $this->support->checkpoint($context, ExecutionStatus::Trying);
        $started = hrtime(true);

        foreach ($tcc->ordered() as $participant) {
            $status = $context->stepStatus($participant->id);
            if ($status === StepStatus::Done || ($participant->optional && $status->isFailure())) {
                continue;
            }

            $input = $inputs->inputFor($participant->id);
            $context->putStepInput($participant->id, $input);
            $context->setStepStatus($participant->id, StepStatus::Running);
            $context->markStepStarted($participant->id, $this->support->now());
            $this->support->events->onParticipantStarted($tcc->name, $context->correlationId, $participant->id, TccPhase::Try);
            $this->support->checkpoint($context, ExecutionStatus::Trying);

            $attemptStarted = hrtime(true);
            try {
                $result = $this->invoker->invoke($tcc->name, $participant->id, $participant->try->invocation, $this->options($participant, TccPhase::Try, $tcc), $context, $input, $input);
            } catch (Throwable $error) {
                $context->setStepStatus($participant->id, $error instanceof TimeoutException ? StepStatus::TimedOut : StepStatus::Failed);
                $context->putStepError($participant->id, $error);
                $this->support->events->onParticipantFailed($tcc->name, $context->correlationId, $participant->id, TccPhase::Try, $error);
                $this->support->events->onStepFailed($tcc->name, $context->correlationId, $participant->id, $error, $context->attempts($participant->id));
                $this->support->publish($this->support->lifecycle, OrchestrationEvent::stepFailed($tcc->name, $context->correlationId, ExecutionPattern::Tcc, $participant->id, $error, $this->support->now()));

                if ($participant->optional) {
                    $this->support->checkpoint($context, ExecutionStatus::Trying);

                    continue;
                }

                // The failure checkpoint already records the switch to CANCEL, so a crash before cancelPhase()
                // resumes into cancelling instead of trying the exhausted participant again.
                $context->setCurrentPhase(TccPhase::Cancel);
                $this->support->checkpoint($context, ExecutionStatus::Canceling);

                $this->support->events->onPhaseFailed($tcc->name, $context->correlationId, TccPhase::Try, $error);

                return [$participant->id, $error];
            }

            $latency = self::since($attemptStarted);
            $context->putTryResult($participant->id, $result);
            $context->putResult($participant->id, $result);
            $context->setLatencyMs($participant->id, $latency);
            $context->setStepStatus($participant->id, StepStatus::Done);
            $context->markCompleted($participant->id);
            $this->support->events->onParticipantSuccess($tcc->name, $context->correlationId, $participant->id, TccPhase::Try);
            $this->support->events->onStepSuccess($tcc->name, $context->correlationId, $participant->id, $context->attempts($participant->id), $latency);
            $this->support->checkpoint($context, ExecutionStatus::Trying);
        }

        $this->support->events->onPhaseCompleted($tcc->name, $context->correlationId, TccPhase::Try, self::since($started));

        return null;
    }

    /** @return array{0: string, 1: Throwable}|null */
    private function confirmPhase(TccDefinition $tcc, ExecutionContext $context): ?array
    {
        $context->setCurrentPhase(TccPhase::Confirm);
        $this->support->events->onPhaseStarted($tcc->name, $context->correlationId, TccPhase::Confirm);
        $this->support->checkpoint($context, ExecutionStatus::Confirming);
        $started = hrtime(true);

        foreach ($tcc->ordered() as $participant) {
            if ($context->stepStatus($participant->id) !== StepStatus::Done) {
                continue;
            }

            $this->support->events->onParticipantStarted($tcc->name, $context->correlationId, $participant->id, TccPhase::Confirm);
            try {
                $result = $this->invoker->invoke($tcc->name, $participant->id.':confirm', $participant->confirm->invocation, $this->options($participant, TccPhase::Confirm, $tcc), $context, $context->stepInput($participant->id), $context->tryResult($participant->id));
            } catch (Throwable $error) {
                $context->setStepStatus($participant->id, StepStatus::ConfirmFailed);
                $context->putStepError($participant->id, $error);
                $this->support->events->onParticipantFailed($tcc->name, $context->correlationId, $participant->id, TccPhase::Confirm, $error);
                $this->support->events->onPhaseFailed($tcc->name, $context->correlationId, TccPhase::Confirm, $error);
                $this->support->publish($this->support->lifecycle, OrchestrationEvent::stepFailed($tcc->name, $context->correlationId, ExecutionPattern::Tcc, $participant->id, $error, $this->support->now()));
                // Saved as CANCEL already: a crash before cancelPhase() must resume into cancelling, not back
                // into CONFIRM, which would skip this participant and report the transaction CONFIRMED.
                $context->setCurrentPhase(TccPhase::Cancel);
                $this->support->checkpoint($context, ExecutionStatus::Canceling);

                return [$participant->id, $error];
            }

            $context->setStepStatus($participant->id, StepStatus::Confirmed);
            $this->support->events->onParticipantSuccess($tcc->name, $context->correlationId, $participant->id, TccPhase::Confirm);

            $confirmed = OrchestrationEvent::stepCompleted($tcc->name, $context->correlationId, ExecutionPattern::Tcc, $participant->id, $result, $this->support->now());
            if ($participant->event !== null) {
                $this->support->publish($this->support->stepEvents, $confirmed->withTopic($participant->event->topic)->withType($participant->event->type)->withKey(SagaExecutionOrchestrator::eventKey($participant->event->key, $context->stepInput($participant->id))));
            }
            $this->support->publish($this->support->lifecycle, $confirmed);
            $this->support->checkpoint($context, ExecutionStatus::Confirming);
        }

        $this->support->events->onPhaseCompleted($tcc->name, $context->correlationId, TccPhase::Confirm, self::since($started));

        return null;
    }

    private function cancelPhase(TccDefinition $tcc, ExecutionContext $context, ?string $failedId, ?TccPhase $failedPhase, ?Throwable $error, bool $includeConfirmed): TccRunOutcome
    {
        $context->setCurrentPhase(TccPhase::Cancel);
        $this->support->events->onPhaseStarted($tcc->name, $context->correlationId, TccPhase::Cancel);
        $this->support->checkpoint($context, ExecutionStatus::Canceling);
        $started = hrtime(true);
        $cancelFailed = false;
        $cancelable = $includeConfirmed
            ? [StepStatus::Done, StepStatus::Confirmed, StepStatus::ConfirmFailed, StepStatus::CancelFailed]
            : [StepStatus::Done, StepStatus::ConfirmFailed, StepStatus::CancelFailed];

        foreach (array_reverse($tcc->ordered()) as $participant) {
            if (! in_array($context->stepStatus($participant->id), $cancelable, true)) {
                continue;
            }

            $this->support->events->onParticipantStarted($tcc->name, $context->correlationId, $participant->id, TccPhase::Cancel);
            try {
                $this->invoker->invoke($tcc->name, $participant->id.':cancel', $participant->cancel->invocation, $this->options($participant, TccPhase::Cancel, $tcc), $context, $context->stepInput($participant->id), $context->tryResult($participant->id));
                $context->setStepStatus($participant->id, StepStatus::Canceled);
                $this->support->events->onParticipantSuccess($tcc->name, $context->correlationId, $participant->id, TccPhase::Cancel);
            } catch (Throwable $cancelError) {
                $cancelFailed = true;
                $context->setStepStatus($participant->id, StepStatus::CancelFailed);
                $context->putCompensationError($participant->id, $cancelError);
                $this->support->events->onParticipantFailed($tcc->name, $context->correlationId, $participant->id, TccPhase::Cancel, $cancelError);
            }
            $this->support->checkpoint($context, ExecutionStatus::Canceling);
        }

        if ($cancelFailed) {
            $this->support->events->onPhaseFailed($tcc->name, $context->correlationId, TccPhase::Cancel, null);
        } else {
            $this->support->events->onPhaseCompleted($tcc->name, $context->correlationId, TccPhase::Cancel, self::since($started));
        }

        $status = $cancelFailed || $failedPhase === TccPhase::Confirm ? ExecutionStatus::Failed : ExecutionStatus::Canceled;

        return new TccRunOutcome($status, $failedId, $failedPhase, $error);
    }

    /**
     * The required failure a resumed transaction is cancelling for: a CONFIRM_FAILED participant, or a FAILED or
     * TIMED_OUT one that is not optional (an optional participant's try failure never cancels the transaction).
     *
     * The CONFIRM_FAILED participant is itself cancelled by the sweep, which overwrites its status with CANCELED
     * or CANCEL_FAILED; a crash after that cancel must still resume into a FAILED transaction with its confirm
     * error. Its step error survives the overwrite, and only a confirm failure leaves one on a participant the
     * sweep can cancel (a try failure stays FAILED and is never cancelled), so that pair also reads as the
     * confirm failure.
     *
     * @return array{0: ?string, 1: ?TccPhase, 2: ?Throwable}
     */
    private function recordedFailure(TccDefinition $tcc, ExecutionContext $context): array
    {
        foreach ($tcc->ordered() as $participant) {
            $status = $context->stepStatus($participant->id);
            $confirmFailed = $status === StepStatus::ConfirmFailed
                || (in_array($status, [StepStatus::Canceled, StepStatus::CancelFailed], true) && $context->stepError($participant->id) !== null);
            $required = $confirmFailed
                || (! $participant->optional && in_array($status, [StepStatus::Failed, StepStatus::TimedOut], true));
            if ($required) {
                return [
                    $participant->id,
                    $confirmFailed ? TccPhase::Confirm : TccPhase::Try,
                    $context->stepError($participant->id) ?? new OrchestrationException("TCC [{$tcc->name}] participant [{$participant->id}] failed."),
                ];
            }
        }

        return [null, null, null];
    }

    private function options(TccParticipantDefinition $participant, TccPhase $phase, TccDefinition $tcc): StepOptions
    {
        return $participant->options($phase, $tcc, $this->settings->defaultTimeout);
    }

    private static function since(int|float $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
