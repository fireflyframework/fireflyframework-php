<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Saga\Engine;

use Firefly\Orchestration\Core\EngineSupport;
use Firefly\Orchestration\Core\Exception\CompensationException;
use Firefly\Orchestration\Core\Exception\ExecutionNotFoundException;
use Firefly\Orchestration\Core\Exception\InvalidExecutionStateException;
use Firefly\Orchestration\Core\Exception\OrchestrationException;
use Firefly\Orchestration\Core\Exception\PatternDisabledException;
use Firefly\Orchestration\Core\ExecutionContext;
use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Event\OrchestrationEvent;
use Firefly\Orchestration\Persistence\ExecutionState;
use Firefly\Orchestration\Report\ExecutionReportBuilder;
use Firefly\Orchestration\Saga\Compensation\SagaCompensator;
use Firefly\Orchestration\Saga\Registry\SagaDefinition;
use Firefly\Orchestration\Saga\Registry\SagaRegistry;
use Firefly\Orchestration\Settings\SagaSettings;
use Throwable;

/**
 * Runs sagas (jafly SagaEngine): execute() a new one, resume() one a crashed process left behind, compensate()
 * one on request (recovery's `compensate` action, a failed composition). Every entry point holds the
 * execution's lock for its whole run; execute() also passes back-pressure admission.
 *
 * The outcome is always a SagaResult — a failed step is compensated and reported, never thrown. What throws is
 * the refusal to run at all: an unknown saga, a disabled pattern, back-pressure, an execution that is not
 * there or is already finished.
 */
final class SagaEngine
{
    public function __construct(
        private readonly SagaRegistry $registry,
        private readonly SagaExecutionOrchestrator $orchestrator,
        private readonly SagaCompensator $compensator,
        private readonly EngineSupport $support,
        private readonly SagaSettings $settings,
    ) {}

    public function registry(): SagaRegistry
    {
        return $this->registry;
    }

    /**
     * @param  array<string, mixed>|StepInputs  $input
     * @param  array<string, string>  $headers
     */
    public function execute(string|SagaDefinition $saga, array|StepInputs $input = [], array $headers = [], ?string $correlationId = null): SagaResult
    {
        $this->guard();
        $definition = is_string($saga) ? $this->registry->get($saga) : $saga;
        $inputs = $input instanceof StepInputs ? $input : StepInputs::of($input);
        $context = ExecutionContext::start(ExecutionPattern::Saga, $definition->name, $inputs->global(), $headers, $correlationId, $this->support->now());

        return $this->support->admission->admit(
            $definition->name,
            fn (): SagaResult => $this->support->lock->synchronized($context->correlationId, fn (): SagaResult => $this->support->tracing->traceExecution(
                $definition->name,
                ExecutionPattern::Saga,
                $context->correlationId,
                fn (): SagaResult => $this->run($definition, $inputs, $context, fresh: true),
            )),
            static fn (SagaResult $result): bool => $result->success,
        );
    }

    public function resume(string $correlationId): SagaResult
    {
        return $this->support->lock->synchronized($correlationId, function () use ($correlationId): SagaResult {
            $state = $this->load($correlationId);
            if ($state->isTerminal()) {
                throw new InvalidExecutionStateException("Saga execution [{$correlationId}] is already {$state->status->value} and cannot be resumed.");
            }

            $definition = $this->registry->get($state->executionName);
            $context = ExecutionContext::fromState($state);
            $inputs = StepInputs::of($state->input);
            foreach ($state->stepInputs as $stepId => $value) {
                $inputs = $inputs->forStep($stepId, $value);
            }

            if ($state->status === ExecutionStatus::Compensating) {
                return $this->fail($definition, $context, $this->firstError($context));
            }

            return $this->support->tracing->traceExecution($definition->name, ExecutionPattern::Saga, $correlationId, fn (): SagaResult => $this->run($definition, $inputs, $context, fresh: false));
        });
    }

    public function compensate(string $correlationId): SagaResult
    {
        return $this->support->lock->synchronized($correlationId, function () use ($correlationId): SagaResult {
            $state = $this->load($correlationId);
            if ($state->isTerminal() && $state->status !== ExecutionStatus::Completed) {
                throw new InvalidExecutionStateException("Saga execution [{$correlationId}] is already {$state->status->value} and has nothing left to compensate.");
            }

            $definition = $this->registry->get($state->executionName);
            $context = ExecutionContext::fromState($state);
            $this->support->checkpoint($context, ExecutionStatus::Compensating);

            $reason = 'compensated on request';
            try {
                $this->compensator->compensate($definition, $context);
            } catch (CompensationException $failure) {
                $reason = $failure->getMessage();
            }

            return $this->finish($definition, $context, ExecutionStatus::Cancelled, null, $reason);
        });
    }

    private function run(SagaDefinition $saga, StepInputs $inputs, ExecutionContext $context, bool $fresh): SagaResult
    {
        if ($fresh) {
            foreach ($inputs->explicit() as $stepId => $value) {
                $context->putStepInput($stepId, $value);
            }
            $this->support->checkpoint($context, ExecutionStatus::Running);
            $this->support->publish($this->support->lifecycle, OrchestrationEvent::executionStarted($saga->name, $context->correlationId, ExecutionPattern::Saga, $this->support->now()));
            $this->support->events->onStart($saga->name, $context->correlationId, ExecutionPattern::Saga);
        }

        // A resumed execution gets a fresh budget from the moment it is resumed: measured from the original
        // start, a saga recovery picks up after the stale threshold would always be past its deadline, and
        // `recovery.action=resume` could only ever compensate it.
        $deadline = $this->settings->defaultTimeout > 0
            ? ($fresh ? $context->startedAt() : $this->support->now())->modify(sprintf('+%d milliseconds', (int) round($this->settings->defaultTimeout * 1000)))
            : null;

        if ($this->orchestrator->orchestrate($saga, $inputs, $context, $deadline)) {
            $result = $this->finish($saga, $context, ExecutionStatus::Completed, null, null);
            $this->support->callbacks->invoke($saga->onComplete, $context, $result);

            return $result;
        }

        return $this->fail($saga, $context, $this->firstError($context));
    }

    private function fail(SagaDefinition $saga, ExecutionContext $context, Throwable $error): SagaResult
    {
        $this->support->checkpoint($context, ExecutionStatus::Compensating, $error->getMessage());

        $compensationFailure = null;
        try {
            $this->compensator->compensate($saga, $context);
        } catch (CompensationException $failure) {
            $compensationFailure = $failure;
        }

        foreach ($context->stepErrors() as $stepId => $stepError) {
            $this->support->deadLetter($context, $stepId, ExecutionStatus::Failed, $stepError);
        }

        $suppressed = $compensationFailure === null && $this->support->callbacks->suppresses($saga->onError, $error);
        $result = $suppressed
            ? $this->finish($saga, $context, ExecutionStatus::Completed, null, null)
            : $this->finish($saga, $context, ExecutionStatus::Failed, $error, $compensationFailure?->getMessage() ?? $error->getMessage());

        $this->support->callbacks->invoke($saga->onError, $context, $result, $error);

        return $result;
    }

    private function finish(SagaDefinition $saga, ExecutionContext $context, ExecutionStatus $status, ?Throwable $error, ?string $reason): SagaResult
    {
        $now = $this->support->now();
        $report = ExecutionReportBuilder::fromContext($context, $status, $reason, $now, $saga->stepIds());
        $result = SagaResult::from($saga, $context, $status, $error, $report, $now);

        $this->support->checkpoint($context, $status, $reason, $report);
        $this->support->publish($this->support->lifecycle, OrchestrationEvent::executionCompleted($saga->name, $context->correlationId, ExecutionPattern::Saga, $status, $now));
        $this->support->events->onCompleted($saga->name, $context->correlationId, ExecutionPattern::Saga, $status->isSuccess(), $this->support->elapsedMs($context));

        return $result;
    }

    private function firstError(ExecutionContext $context): Throwable
    {
        foreach ($context->stepErrors() as $error) {
            return $error;
        }

        return new OrchestrationException("Saga [{$context->executionName}] failed.");
    }

    private function load(string $correlationId): ExecutionState
    {
        $state = $this->support->persistence->findById($correlationId);
        if ($state === null || $state->pattern !== ExecutionPattern::Saga) {
            throw new ExecutionNotFoundException($correlationId);
        }

        return $state;
    }

    private function guard(): void
    {
        if (! $this->settings->enabled) {
            throw new PatternDisabledException(ExecutionPattern::Saga, 'firefly.orchestration.saga.enabled');
        }
    }
}
