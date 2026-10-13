<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Workflow\Engine;

use Firefly\Kernel\Exception\Infrastructure\TimeoutException;
use Firefly\Orchestration\Core\Condition\ConditionEvaluator;
use Firefly\Orchestration\Core\EngineSupport;
use Firefly\Orchestration\Core\Exception\OrchestrationException;
use Firefly\Orchestration\Core\Exception\StepTimeoutException;
use Firefly\Orchestration\Core\ExecutionContext;
use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Core\StepInvoker;
use Firefly\Orchestration\Core\StepStatus;
use Firefly\Orchestration\Event\OrchestrationEvent;
use Firefly\Orchestration\Persistence\ExecutionState;
use Firefly\Orchestration\Workflow\Registry\WorkflowDefinition;
use Firefly\Orchestration\Workflow\Registry\WorkflowStepDefinition;
use Throwable;

/**
 * Advances a workflow as far as it can go right now (jafly WorkflowExecutor), one step after another in layer
 * order (spec decision D2): a settled step is skipped, a PENDING step whose condition is false is SKIPPED, a
 * wait that is not satisfied stops the pass WAITING, a child step returns a child-start request the engine
 * fulfils, a step returning ContinueAsNew ends the pass. Each step is checkpointed RUNNING before it is
 * invoked and again with its result. Every step receives the workflow input; earlier results are reached with
 * #[FromStep] / $ctx->result().
 */
final class WorkflowExecutor
{
    public function __construct(
        private readonly StepInvoker $invoker,
        private readonly EngineSupport $support,
        private readonly ConditionEvaluator $conditions,
        private readonly WaitEvaluator $waits,
    ) {}

    public function run(WorkflowDefinition $workflow, ExecutionContext $context): WorkflowRunOutcome
    {
        $context->setTopologyLayers($workflow->layers);
        $now = $this->support->now();

        $deadline = $context->deadline();
        if ($deadline !== null && $now >= $deadline) {
            return WorkflowRunOutcome::failed($context->waitingOn() ?? '', new StepTimeoutException("Workflow [{$workflow->id}] passed its timeoutMs deadline of {$workflow->timeoutMs} ms."), timedOut: true);
        }

        foreach ($workflow->layers as $layer) {
            foreach ($layer as $stepId) {
                $step = $workflow->steps[$stepId];
                $status = $context->stepStatus($stepId);

                if ($status->isSettled()) {
                    continue;
                }
                if ($status->isFailure()) {
                    return WorkflowRunOutcome::failed($stepId, $context->stepError($stepId) ?? new OrchestrationException("Workflow [{$workflow->id}] step [{$stepId}] failed."));
                }

                if ($status === StepStatus::Pending && $step->condition !== '' && ! $this->conditions->evaluate($step->condition, self::conditionValues($context))) {
                    $context->setStepStatus($stepId, StepStatus::Skipped);
                    $this->support->events->onStepSkipped($workflow->id, $context->correlationId, $stepId);
                    $this->support->checkpoint($context, ExecutionStatus::Running);

                    continue;
                }

                if ($step->wait !== null && $status !== StepStatus::Running) {
                    try {
                        $satisfied = $this->waits->satisfied($workflow->id, $step, $context, $now);
                    } catch (Throwable $error) {
                        return $this->failed($workflow, $context, $stepId, $error);
                    }
                    if (! $satisfied) {
                        $context->setStepStatus($stepId, StepStatus::Waiting);
                        $context->setWaitingOn($stepId);

                        return WorkflowRunOutcome::waiting($stepId);
                    }
                }

                $childId = $step->child === null ? null : $context->child($stepId);
                if ($step->child !== null && $childId !== null) {
                    // The child's own wake-up (WorkflowEngine::notifyParent) can be missed: the parent was
                    // SUSPENDED when the child finished, or its lock was busy. So every drive of a parent still
                    // waiting on a child looks at the child's persisted state first, and settles the step itself.
                    $child = $this->settledChild($childId);
                    if ($child !== null) {
                        $this->completeChild($workflow, $context, $stepId, $child);
                        if ($context->stepStatus($stepId)->isFailure()) {
                            return WorkflowRunOutcome::failed($stepId, $context->stepError($stepId) ?? new OrchestrationException("Workflow [{$workflow->id}] step [{$stepId}] failed."));
                        }

                        continue;
                    }
                    if ($context->timerFired("{$stepId}:child-timeout", $now)) {
                        $context->removeTimer("{$stepId}:child-timeout");

                        return $this->failed($workflow, $context, $stepId, new StepTimeoutException("Workflow [{$workflow->id}] step [{$stepId}]: child workflow [{$step->child->workflowId}] did not finish within {$step->child->timeoutMs} ms."));
                    }
                    $context->setStepStatus($stepId, StepStatus::Waiting);
                    $context->setWaitingOn($stepId);

                    return WorkflowRunOutcome::waiting($stepId);
                }

                $started = hrtime(true);
                try {
                    $result = $this->invoke($workflow, $step, $context);
                } catch (Throwable $error) {
                    $context->setLatencyMs($stepId, self::since($started));

                    return $this->failed($workflow, $context, $stepId, $error);
                }

                if ($step->child !== null) {
                    return WorkflowRunOutcome::child($stepId, self::childInput($result));
                }

                if ($result instanceof ContinueAsNew) {
                    $context->setStepStatus($stepId, StepStatus::Done);
                    $context->markCompleted($stepId);

                    return WorkflowRunOutcome::continueAsNew($stepId, $result);
                }

                $this->finishStep($workflow, $context, $stepId, $result, self::since($started));
            }
        }

        return WorkflowRunOutcome::completed();
    }

    public function finishStep(WorkflowDefinition $workflow, ExecutionContext $context, string $stepId, mixed $result, int $latencyMs = 0): void
    {
        $step = $workflow->step($stepId);
        $context->putResult($stepId, $result);
        $context->setLatencyMs($stepId, $latencyMs);
        $context->setStepStatus($stepId, StepStatus::Done);
        $context->markCompleted($stepId);
        $context->setWaitingOn(null);
        $this->support->events->onStepSuccess($workflow->id, $context->correlationId, $stepId, $context->attempts($stepId), $latencyMs);

        $completed = OrchestrationEvent::stepCompleted($workflow->id, $context->correlationId, ExecutionPattern::Workflow, $stepId, $result, $this->support->now());
        if ($step->outputEventType !== '') {
            $this->support->publish($this->support->stepEvents, $completed->withType($step->outputEventType));
        }
        if ($workflow->publishEvents) {
            $this->support->publish($this->support->lifecycle, $completed);
        }

        $this->support->callbacks->invoke($workflow->onStepComplete, $context, $result, null, $stepId);
        $this->support->checkpoint($context, ExecutionStatus::Running);
    }

    public function completeChild(WorkflowDefinition $workflow, ExecutionContext $context, string $stepId, ExecutionState $child): void
    {
        $success = $child->status->isSuccess();
        $this->support->events->onChildWorkflowCompleted($workflow->id, $context->correlationId, $child->correlationId, $success);
        $context->removeTimer("{$stepId}:child-timeout");

        if ($success) {
            $this->finishStep($workflow, $context, $stepId, ['correlationId' => $child->correlationId, 'status' => $child->status->value, 'results' => $child->stepResults]);

            return;
        }

        $error = new OrchestrationException(
            "Child workflow [{$child->executionName}] ({$child->correlationId}) of step [{$stepId}] ended {$child->status->value}".($child->failureReason === null ? '.' : ": {$child->failureReason}"),
            'ORCHESTRATION_CHILD_FAILED',
        );
        $context->setWaitingOn(null);
        $this->failed($workflow, $context, $stepId, $error);
    }

    public function compensate(WorkflowDefinition $workflow, ExecutionContext $context): void
    {
        $order = array_values(array_filter(
            array_reverse($context->completionOrder()),
            static fn (string $stepId): bool => isset($workflow->steps[$stepId])
                && $workflow->steps[$stepId]->compensatable
                && $workflow->steps[$stepId]->compensation !== null
                && $context->stepStatus($stepId) === StepStatus::Done,
        ));
        if ($order === []) {
            return;
        }

        $this->support->events->onCompensationStarted($workflow->id, $context->correlationId);
        foreach ($order as $stepId) {
            $step = $workflow->steps[$stepId];
            $compensation = $step->compensation;
            if ($compensation === null) {
                continue;
            }

            try {
                $result = $this->invoker->invoke($workflow->id, $stepId.':compensate', $compensation, $step->options->withoutIdempotency(), $context, $context->stepInput($stepId), $context->result($stepId));
                $context->putCompensationResult($stepId, $result);
                $context->setStepStatus($stepId, StepStatus::Compensated);
                $this->support->events->onStepCompensated($workflow->id, $context->correlationId, $stepId);
            } catch (Throwable $error) {
                $context->putCompensationError($stepId, $error);
                $context->setStepStatus($stepId, StepStatus::CompensationFailed);
                $this->support->events->onStepCompensationFailed($workflow->id, $context->correlationId, $stepId, $error);
            }
            $this->support->checkpoint($context, ExecutionStatus::Compensating);
        }
    }

    private function invoke(WorkflowDefinition $workflow, WorkflowStepDefinition $step, ExecutionContext $context): mixed
    {
        $input = $context->input();
        $context->putStepInput($step->id, $input);
        $context->setStepStatus($step->id, StepStatus::Running);
        $context->markStepStarted($step->id, $this->support->now());
        $context->setWaitingOn(null);
        $context->clearStepError($step->id);
        $this->support->events->onStepStarted($workflow->id, $context->correlationId, $step->id);
        $this->support->checkpoint($context, ExecutionStatus::Running);

        return $this->invoker->invoke($workflow->id, $step->id, $step->invocation, $step->options, $context, $input, $input);
    }

    /**
     * Records `$error` as the failure of `$stepId` and returns the FAILED outcome the engine settles (compensate,
     * dead-letter, FAILED); the engine calls it too when a child step's child cannot even be started.
     */
    public function failed(WorkflowDefinition $workflow, ExecutionContext $context, string $stepId, Throwable $error): WorkflowRunOutcome
    {
        $context->setStepStatus($stepId, $error instanceof TimeoutException ? StepStatus::TimedOut : StepStatus::Failed);
        $context->putStepError($stepId, $error);
        $this->support->events->onStepFailed($workflow->id, $context->correlationId, $stepId, $error, $context->attempts($stepId));
        $this->support->publish($this->support->lifecycle, OrchestrationEvent::stepFailed($workflow->id, $context->correlationId, ExecutionPattern::Workflow, $stepId, $error, $this->support->now()));

        return WorkflowRunOutcome::failed($stepId, $error);
    }

    /**
     * The final run of child `$childId` once it is terminal, following its continue-as-new chain (a run that
     * continued as new is COMPLETED, but the child is only finished when the last run of the chain is); null
     * while the child is still going, or when its row is gone.
     */
    private function settledChild(string $childId): ?ExecutionState
    {
        $seen = [];
        $state = $this->support->persistence->findById($childId);
        while ($state !== null && $state->isTerminal()) {
            $next = $state->variables['continuedAs'] ?? null;
            if (! is_string($next) || $next === '' || isset($seen[$next])) {
                return $state;
            }
            $seen[$next] = true;
            $state = $this->support->persistence->findById($next);
        }

        return null;
    }

    /** @return array<string, mixed> */
    private static function conditionValues(ExecutionContext $context): array
    {
        return ['input' => $context->input(), 'results' => $context->results(), 'variables' => $context->variables(), 'headers' => $context->headers()];
    }

    /** @return array<string, mixed> */
    private static function childInput(mixed $result): array
    {
        if (! is_array($result)) {
            return $result === null ? [] : ['value' => $result];
        }

        $input = [];
        foreach ($result as $key => $value) {
            $input[(string) $key] = $value;
        }

        return $input;
    }

    private static function since(int|float $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
