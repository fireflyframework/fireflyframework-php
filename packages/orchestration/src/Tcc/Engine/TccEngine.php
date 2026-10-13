<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tcc\Engine;

use Firefly\Orchestration\Core\EngineSupport;
use Firefly\Orchestration\Core\Exception\ExecutionNotFoundException;
use Firefly\Orchestration\Core\Exception\InvalidExecutionStateException;
use Firefly\Orchestration\Core\Exception\PatternDisabledException;
use Firefly\Orchestration\Core\ExecutionContext;
use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Core\StepStatus;
use Firefly\Orchestration\Event\OrchestrationEvent;
use Firefly\Orchestration\Persistence\ExecutionState;
use Firefly\Orchestration\Report\ExecutionReportBuilder;
use Firefly\Orchestration\Settings\TccSettings;
use Firefly\Orchestration\Tcc\Registry\TccDefinition;
use Firefly\Orchestration\Tcc\Registry\TccRegistry;

/**
 * Runs TCC transactions (jafly TccEngine): execute(), resume() from the checkpointed phase, compensate()
 * (cancel every tried participant, confirmed ones included — a composition or recovery undoing a CONFIRMED
 * transaction; "tried" covers a try that timed out or was left RUNNING by a crash, cancelled with a null try
 * result). Every entry point holds the execution lock; execute() passes back-pressure admission.
 *
 * As with SagaEngine, the outcome is always a TccResult — a failed phase is cancelled and reported, never
 * thrown. What throws is the refusal to run at all: an unknown TCC, a disabled pattern, back-pressure, a
 * correlation id that already exists, an execution that is not there or is already finished. A cancel on
 * request (compensate()) is not a failure of the transaction: it neither dead-letters nor calls #[OnTccError],
 * and a resume() after a crash in the middle of one finishes it as a cancel on request. A cancel on request
 * that ended FAILED with CANCEL_FAILED participants can be compensated again to retry just those cancels.
 */
final class TccEngine
{
    public function __construct(
        private readonly TccRegistry $registry,
        private readonly TccExecutionOrchestrator $orchestrator,
        private readonly EngineSupport $support,
        private readonly TccSettings $settings,
    ) {}

    public function registry(): TccRegistry
    {
        return $this->registry;
    }

    /**
     * @param  array<string, mixed>|TccInputs  $input
     * @param  array<string, string>  $headers
     */
    public function execute(string|TccDefinition $tcc, array|TccInputs $input = [], array $headers = [], ?string $correlationId = null): TccResult
    {
        if (! $this->settings->enabled) {
            throw new PatternDisabledException(ExecutionPattern::Tcc, 'firefly.orchestration.tcc.enabled');
        }

        $definition = is_string($tcc) ? $this->registry->get($tcc) : $tcc;
        $inputs = $input instanceof TccInputs ? $input : TccInputs::of($input);
        $context = ExecutionContext::start(ExecutionPattern::Tcc, $definition->name, $inputs->global(), $headers, $correlationId, $this->support->now());

        // Checked twice, as SagaEngine does: up front, so a retried call is refused before admission, whose
        // circuit breaker would count the refusal as a failed execution; and again under the lock, so two
        // concurrent calls with one id cannot both pass.
        $this->refuseExisting($context->correlationId);

        return $this->support->admission->admit(
            $definition->name,
            fn (): TccResult => $this->support->lock->synchronized($context->correlationId, function () use ($definition, $inputs, $context): TccResult {
                $this->refuseExisting($context->correlationId);

                return $this->support->tracing->traceExecution(
                    $definition->name,
                    ExecutionPattern::Tcc,
                    $context->correlationId,
                    function () use ($definition, $inputs, $context): TccResult {
                        foreach ($inputs->explicit() as $participantId => $value) {
                            $context->putStepInput($participantId, $value);
                        }
                        $this->support->checkpoint($context, ExecutionStatus::Trying);
                        $this->support->publish($this->support->lifecycle, OrchestrationEvent::executionStarted($definition->name, $context->correlationId, ExecutionPattern::Tcc, $this->support->now()));
                        $this->support->events->onStart($definition->name, $context->correlationId, ExecutionPattern::Tcc);

                        return $this->finish($definition, $context, $this->orchestrator->orchestrate($definition, $inputs, $context));
                    },
                );
            }),
            static fn (TccResult $result): bool => $result->success,
        );
    }

    public function resume(string $correlationId): TccResult
    {
        return $this->support->lock->synchronized($correlationId, function () use ($correlationId): TccResult {
            $state = $this->load($correlationId);
            if ($state->isTerminal()) {
                throw new InvalidExecutionStateException("TCC execution [{$correlationId}] is already {$state->status->value} and cannot be resumed.");
            }

            $definition = $this->registry->get($state->executionName);
            $context = ExecutionContext::fromState($state);
            $inputs = TccInputs::of($state->input);
            foreach ($state->stepInputs as $participantId => $value) {
                $inputs = $inputs->forParticipant($participantId, $value);
            }

            // A cancel on request interrupted by a crash goes on as one: confirmed participants are cancelled too,
            // and the cancel neither dead-letters nor calls #[OnTccError], exactly as compensate() would have.
            if ($this->orchestrator->cancelRequested($context)) {
                return $this->support->tracing->traceExecution($definition->name, ExecutionPattern::Tcc, $correlationId, fn (): TccResult => $this->finish($definition, $context, $this->orchestrator->cancelAll($definition, $context), onRequest: true));
            }

            return $this->support->tracing->traceExecution($definition->name, ExecutionPattern::Tcc, $correlationId, fn (): TccResult => $this->finish($definition, $context, $this->orchestrator->orchestrate($definition, $inputs, $context)));
        });
    }

    public function compensate(string $correlationId): TccResult
    {
        return $this->support->lock->synchronized($correlationId, function () use ($correlationId): TccResult {
            $state = $this->load($correlationId);
            $context = ExecutionContext::fromState($state);
            if ($state->isTerminal() && $state->status !== ExecutionStatus::Confirmed && ! $this->retriesCancel($state, $context)) {
                throw new InvalidExecutionStateException("TCC execution [{$correlationId}] is already {$state->status->value} and has nothing left to cancel.");
            }

            $definition = $this->registry->get($state->executionName);

            return $this->finish($definition, $context, $this->orchestrator->cancelAll($definition, $context), onRequest: true);
        });
    }

    /**
     * @param  bool  $onRequest  compensate(): no dead letter and no callbacks, the cancel was asked for
     */
    private function finish(TccDefinition $tcc, ExecutionContext $context, TccRunOutcome $outcome, bool $onRequest = false): TccResult
    {
        $now = $this->support->now();
        $error = $outcome->error;
        $suppressed = ! $onRequest && $outcome->status !== ExecutionStatus::Confirmed && $error !== null && $this->support->callbacks->suppresses($tcc->onError, $error);
        $reason = match (true) {
            $outcome->status === ExecutionStatus::Confirmed => null,
            $error !== null => $error->getMessage(),
            $outcome->status === ExecutionStatus::Canceled => $onRequest ? 'canceled on request' : 'canceled',
            default => self::cancelFailures($context),
        };
        $report = ExecutionReportBuilder::fromContext($context, $outcome->status, $reason, $now, $tcc->participantIds());
        $result = TccResult::from($tcc, $context, $outcome, $outcome->status === ExecutionStatus::Confirmed || $suppressed, $report, $now);

        $this->support->checkpoint($context, $outcome->status, $reason, $report);
        if (! $onRequest && $error !== null) {
            $this->support->deadLetter($context, $outcome->failedParticipantId, $outcome->status, $error);
        }
        $this->support->publish($this->support->lifecycle, OrchestrationEvent::executionCompleted($tcc->name, $context->correlationId, ExecutionPattern::Tcc, $outcome->status, $now));
        $this->support->events->onCompleted($tcc->name, $context->correlationId, ExecutionPattern::Tcc, $outcome->status === ExecutionStatus::Confirmed, $this->support->elapsedMs($context));

        if ($onRequest) {
            return $result;
        }

        if ($outcome->status === ExecutionStatus::Confirmed) {
            $this->support->callbacks->invoke($tcc->onComplete, $context, $result);
        } else {
            $this->support->callbacks->invoke($tcc->onError, $context, $result, $error);
        }

        return $result;
    }

    /**
     * A cancel on request that ended FAILED because some cancels failed may be asked again: the sweep skips
     * what it already cancelled and retries only the CANCEL_FAILED participants (TccCompositor's
     * retry-with-backoff policy relies on it). A transaction that FAILED on its own — a confirm failure — is
     * not reopened: its confirmed participants stay confirmed, as the TCC protocol left them.
     */
    private function retriesCancel(ExecutionState $state, ExecutionContext $context): bool
    {
        return $state->status === ExecutionStatus::Failed
            && $this->orchestrator->cancelRequested($context)
            && in_array(StepStatus::CancelFailed, $state->stepStatuses, true);
    }

    /** Why a cancel sweep that raised no error of its own still ended FAILED: the cancels that failed. */
    private static function cancelFailures(ExecutionContext $context): string
    {
        $failures = [];
        foreach ($context->compensationErrors() as $participantId => $failure) {
            $failures[] = "[{$participantId}] {$failure->getMessage()}";
        }

        return $failures === [] ? 'canceled' : 'cancel failed: '.implode('; ', $failures);
    }

    /**
     * A caller-supplied correlation id that is already persisted belongs to an execution that exists: starting
     * it again would overwrite its row and try every participant a second time over reservations it already
     * holds.
     */
    private function refuseExisting(string $correlationId): void
    {
        $state = $this->support->persistence->findById($correlationId);
        if ($state !== null) {
            throw new InvalidExecutionStateException("Execution [{$correlationId}] already exists ({$state->pattern->value} {$state->executionName}, {$state->status->value}); use resume() or compensate() instead of starting it again.");
        }
    }

    private function load(string $correlationId): ExecutionState
    {
        $state = $this->support->persistence->findById($correlationId);
        if ($state === null || $state->pattern !== ExecutionPattern::Tcc) {
            throw new ExecutionNotFoundException($correlationId);
        }

        return $state;
    }
}
