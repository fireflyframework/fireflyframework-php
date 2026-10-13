<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Saga\Compensation;

use Firefly\Kernel\Exception\Infrastructure\CircuitBreakerOpenException;
use Firefly\Orchestration\Core\CompensationPolicy;
use Firefly\Orchestration\Core\EngineSupport;
use Firefly\Orchestration\Core\Exception\CompensationException;
use Firefly\Orchestration\Core\ExecutionContext;
use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Core\StepInvoker;
use Firefly\Orchestration\Core\StepOptions;
use Firefly\Orchestration\Core\StepStatus;
use Firefly\Orchestration\Saga\Registry\SagaDefinition;
use Firefly\Resilience\CircuitBreaker;
use Firefly\Resilience\ResilienceRegistry;
use Firefly\Resilience\Store\ResilienceStore;
use Throwable;

/**
 * Undoes the completed steps of a failed saga (jafly SagaCompensator), by `firefly.orchestration.saga.
 * compensation-policy`. Only steps that are DONE are compensated — a step that failed did nothing to undo,
 * and a step already COMPENSATED (a resumed compensation) is not undone twice. Every compensation receives
 * the step RESULT as its implicit argument and is checkpointed, so a crash during compensation resumes it.
 *
 *  - STRICT_SEQUENTIAL: reverse completion order, one attempt each, failures to the error handler.
 *  - GROUPED_PARALLEL: reverse layer order, declaration order inside a layer (sequential: spec decision D2).
 *  - RETRY_WITH_BACKOFF: reverse completion order with the step's compensation retry/backoff/timeout.
 *  - CIRCUIT_BREAKER: reverse completion order through a firefly/resilience breaker per saga; a failed
 *    `compensationCritical` step, or an open breaker, stops the sweep and throws a CompensationException
 *    naming the step that stopped it and the steps left DONE. Nothing re-runs them automatically: the
 *    execution ends with that message as its reason, for an operator to act on.
 *  - BEST_EFFORT: reverse completion order, every compensation attempted, FAIL_SAGA ignored.
 *
 * The error handler's RETRY answer re-runs the compensation after the step's compensation backoff (under
 * CIRCUIT_BREAKER through the saga's breaker, so retries count towards its threshold); a handler
 * that keeps answering RETRY is cut off after MAX_HANDLER_RETRIES and treated as CONTINUE.
 */
final class SagaCompensator
{
    public const int MAX_HANDLER_RETRIES = 25;

    public function __construct(
        private readonly StepInvoker $invoker,
        private readonly EngineSupport $support,
        private readonly CompensationPolicy $policy,
        private readonly CompensationErrorHandler $handler,
        private readonly ?ResilienceRegistry $resilience = null,
        private readonly ?ResilienceStore $store = null,
    ) {}

    public function policy(): CompensationPolicy
    {
        return $this->policy;
    }

    public function compensate(SagaDefinition $saga, ExecutionContext $context): void
    {
        $completed = array_values(array_filter(
            $context->completionOrder(),
            static fn (string $stepId): bool => isset($saga->steps[$stepId]) && $context->stepStatus($stepId) === StepStatus::Done,
        ));
        if ($completed === []) {
            return;
        }

        $this->support->events->onCompensationStarted($saga->name, $context->correlationId);
        $reversed = array_reverse($completed);

        match ($this->policy) {
            CompensationPolicy::StrictSequential => $this->sequentially($saga, $context, $reversed, withRetry: false, honourFailSaga: true),
            CompensationPolicy::RetryWithBackoff => $this->sequentially($saga, $context, $reversed, withRetry: true, honourFailSaga: true),
            CompensationPolicy::BestEffort => $this->sequentially($saga, $context, $reversed, withRetry: false, honourFailSaga: false),
            CompensationPolicy::GroupedParallel => $this->sequentially($saga, $context, $this->byLayer($saga, $completed), withRetry: false, honourFailSaga: true),
            CompensationPolicy::CircuitBreaker => $this->throughBreaker($saga, $context, $reversed),
        };
    }

    /**
     * @param  list<string>  $order
     */
    private function sequentially(SagaDefinition $saga, ExecutionContext $context, array $order, bool $withRetry, bool $honourFailSaga): void
    {
        foreach ($order as $stepId) {
            $step = $saga->steps[$stepId];
            if ($step->compensation === null) {
                continue;
            }

            $options = $withRetry ? $step->compensationOptions() : new StepOptions(timeoutMs: $step->compensationOptions()->timeoutMs);
            $error = $this->attempt($saga, $context, $stepId, $options, null);
            if ($error !== null) {
                $this->onFailure($saga, $context, $stepId, $error, $honourFailSaga);
            }
        }
    }

    /**
     * Compensates in reverse completion order through the saga's breaker. When the breaker refuses a step (or
     * opens during the sweep), or a `compensationCritical` step fails, the sweep stops and a
     * CompensationException names the step that stopped it and the steps left DONE. The engine records that
     * message as the execution's reason, so a FAILED or CANCELLED saga never claims a compensation it did not
     * finish.
     *
     * @param  list<string>  $reversed
     */
    private function throughBreaker(SagaDefinition $saga, ExecutionContext $context, array $reversed): void
    {
        $breaker = $this->breaker($saga->name);
        $left = [];
        $stoppedAt = null;
        $critical = false;
        $lastError = null;

        foreach ($reversed as $stepId) {
            $step = $saga->steps[$stepId];
            if ($step->compensation === null) {
                continue;
            }
            if ($stoppedAt !== null) {
                $left[] = $stepId;

                continue;
            }

            try {
                $error = $this->attempt($saga, $context, $stepId, new StepOptions(timeoutMs: $step->compensationOptions()->timeoutMs), $breaker);
            } catch (CircuitBreakerOpenException $refused) {
                $stoppedAt = $stepId;
                $lastError ??= $refused;
                $left[] = $stepId;

                continue;
            }

            if ($error === null) {
                continue;
            }
            $lastError = $error;
            if ($step->compensationCritical) {
                $stoppedAt = $stepId;
                $critical = true;

                continue;
            }
            if ($breaker !== null && $breaker->state() === 'open') {
                $stoppedAt = $stepId;

                continue;
            }

            // The handler's retries go through the same breaker: they count towards its failure threshold, and a
            // retry it refuses — or one that opens it — stops the sweep like any other open circuit.
            if ($this->onFailure($saga, $context, $stepId, $error, honourFailSaga: true, breaker: $breaker)) {
                $stoppedAt = $stepId;
                $lastError = $context->compensationError($stepId) ?? $error;
            }
        }

        if ($stoppedAt === null) {
            return;
        }

        $message = $critical
            ? "Saga [{$saga->name}] stopped compensating at critical step [{$stoppedAt}]"
            : "Saga [{$saga->name}] opened its compensation circuit at step [{$stoppedAt}]";
        if ($left !== []) {
            $message .= '; ['.implode(', ', $left).'] left uncompensated';
        }

        // Every compensated step was already checkpointed under COMPENSATING; the steps left stay DONE, and the
        // exception ends the execution with this message as its reason, so an operator sees what to undo.
        $this->support->logger->warning($message.'.', ['correlationId' => $context->correlationId]);
        $this->support->checkpoint($context, ExecutionStatus::Compensating);

        throw new CompensationException($message.($lastError === null ? '.' : ": {$lastError->getMessage()}"), $lastError);
    }

    /** Runs one compensation; returns its error, or null when it succeeded. Throws only when `$breaker` refused admission. */
    private function attempt(SagaDefinition $saga, ExecutionContext $context, string $stepId, StepOptions $options, ?CircuitBreaker $breaker): ?Throwable
    {
        $step = $saga->steps[$stepId];
        $compensation = $step->compensation;
        if ($compensation === null) {
            return null;
        }

        // Only OUR breaker refusing admission stops the sweep: a compensation that itself calls a downstream
        // service behind an open breaker failed like any other compensation and goes to the error handler.
        $ran = false;
        $run = function () use ($saga, $stepId, $compensation, $options, $context, &$ran): mixed {
            $ran = true;

            return $this->invoker->invoke($saga->name, $stepId.':compensate', $compensation, $options, $context, $context->stepInput($stepId), $context->result($stepId));
        };

        try {
            $result = $breaker === null ? $run() : $breaker->call($run);
        } catch (Throwable $error) {
            if ($error instanceof CircuitBreakerOpenException && $breaker !== null && ! $ran) {
                throw $error;
            }

            $context->putCompensationError($stepId, $error);
            $context->setStepStatus($stepId, StepStatus::CompensationFailed);
            $this->support->events->onStepCompensationFailed($saga->name, $context->correlationId, $stepId, $error);
            $this->support->checkpoint($context, ExecutionStatus::Compensating);

            return $error;
        }

        $context->putCompensationResult($stepId, $result);
        $this->markCompensated($saga, $context, $stepId);

        return null;
    }

    /**
     * Asks the error handler what to do with a failed compensation and carries out its answer. Under
     * CIRCUIT_BREAKER `$breaker` is the saga's breaker and every RETRY runs through it; the method then
     * returns true when that breaker refused a retry or opened after one, so the caller stops the sweep.
     * Without a breaker it always returns false.
     */
    private function onFailure(SagaDefinition $saga, ExecutionContext $context, string $stepId, Throwable $error, bool $honourFailSaga, ?CircuitBreaker $breaker = null): bool
    {
        $step = $saga->steps[$stepId];

        for ($attempt = 0; $attempt < self::MAX_HANDLER_RETRIES; $attempt++) {
            $decision = $this->handler->handle($saga->name, $stepId, $error, $attempt);

            if ($decision === CompensationErrorResult::Continue || $decision === CompensationErrorResult::SkipStep) {
                return false;
            }

            if ($decision === CompensationErrorResult::MarkCompensated) {
                $this->markCompensated($saga, $context, $stepId);

                return false;
            }

            if ($decision === CompensationErrorResult::FailSaga) {
                if ($honourFailSaga) {
                    throw new CompensationException("Saga [{$saga->name}] stopped compensating at step [{$stepId}]: {$error->getMessage()}", $error);
                }

                return false;
            }

            $backoff = $step->compensationOptions()->backoffMs;
            if ($backoff > 0) {
                usleep($backoff * 1000);
            }

            try {
                $retryError = $this->attempt($saga, $context, $stepId, new StepOptions(timeoutMs: $step->compensationOptions()->timeoutMs), $breaker);
            } catch (CircuitBreakerOpenException) {
                return true;
            }
            if ($retryError === null) {
                return false;
            }
            if ($breaker !== null && $breaker->state() === 'open') {
                return true;
            }
            $error = $retryError;
        }

        $this->support->logger->warning("Saga [{$saga->name}] stopped retrying the compensation of step [{$stepId}] after ".self::MAX_HANDLER_RETRIES.' handler retries.', [
            'correlationId' => $context->correlationId,
        ]);

        return false;
    }

    private function markCompensated(SagaDefinition $saga, ExecutionContext $context, string $stepId): void
    {
        $context->setStepStatus($stepId, StepStatus::Compensated);
        $this->support->events->onStepCompensated($saga->name, $context->correlationId, $stepId);
        $this->support->checkpoint($context, ExecutionStatus::Compensating);
    }

    /**
     * @param  list<string>  $completed
     * @return list<string>
     */
    private function byLayer(SagaDefinition $saga, array $completed): array
    {
        $order = [];
        foreach (array_reverse($saga->layers) as $layer) {
            foreach ($layer as $stepId) {
                if (in_array($stepId, $completed, true)) {
                    $order[] = $stepId;
                }
            }
        }

        return $order;
    }

    private function breaker(string $sagaName): ?CircuitBreaker
    {
        // Fall back to the default breaker only when firefly.resilience.circuit-breaker has no instance for this
        // saga. A configured instance with an invalid value throws its ConfigurationException here instead of
        // silently running on thresholds the operator never set.
        $name = 'orchestration-compensation-'.$sagaName;
        if ($this->resilience !== null && $this->resilience->hasCircuitBreaker($name)) {
            return $this->resilience->circuitBreaker($name);
        }

        return $this->store === null ? null : new CircuitBreaker(
            key: 'firefly:orchestration:compensation:'.$sagaName,
            store: $this->store,
            failureThreshold: 5,
            waitDurationInOpen: 30.0,
        );
    }
}
