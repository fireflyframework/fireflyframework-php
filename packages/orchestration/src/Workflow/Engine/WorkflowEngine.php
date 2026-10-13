<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Workflow\Engine;

use Firefly\Orchestration\Core\ClosureInvocation;
use Firefly\Orchestration\Core\EngineSupport;
use Firefly\Orchestration\Core\Exception\ExecutionLockedException;
use Firefly\Orchestration\Core\Exception\ExecutionNotFoundException;
use Firefly\Orchestration\Core\Exception\InvalidExecutionStateException;
use Firefly\Orchestration\Core\Exception\OrchestrationException;
use Firefly\Orchestration\Core\Exception\PatternDisabledException;
use Firefly\Orchestration\Core\ExecutionContext;
use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Core\MethodInvocation;
use Firefly\Orchestration\Core\StepStatus;
use Firefly\Orchestration\Core\TriggerMode;
use Firefly\Orchestration\Event\OrchestrationEvent;
use Firefly\Orchestration\Persistence\ExecutionCriteria;
use Firefly\Orchestration\Persistence\ExecutionState;
use Firefly\Orchestration\Report\ExecutionReportBuilder;
use Firefly\Orchestration\Settings\WorkflowSettings;
use Firefly\Orchestration\Workflow\Queue\WorkflowDispatcher;
use Firefly\Orchestration\Workflow\Registry\WorkflowDefinition;
use Firefly\Orchestration\Workflow\Registry\WorkflowRegistry;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs durable workflows (jafly WorkflowEngine plus its Signal/Timer/ChildWorkflow/ContinueAsNew/Query/Search
 * services, folded into one class because in a synchronous process they all do the same thing: load a
 * checkpoint under its lock, change it, and drive the executor until it waits, completes or fails).
 *
 * A SYNC start drives inline; an ASYNC start persists PENDING and dispatches a RunWorkflowJob. A WAITING
 * workflow is re-driven by signal(), by the tick (resumeDue()) once a timer or its deadline is due, and by a
 * child's completion. A child that finishes while its parent is being driven in THIS process is completed
 * inline by that drive; one that finishes elsewhere (a queue worker) wakes the parent itself. A parent that is
 * being cancelled or compensated cascades to its children, and those children do not wake it back up: the
 * parent is settling, so it is not re-driven from inside its own cancel()/compensate().
 */
final class WorkflowEngine
{
    public const string CONTINUED_FROM_HEADER = 'x-firefly-continued-from';

    /** @var array<string, true> the executions this process is driving right now */
    private array $driving = [];

    /** @var array<string, true> the executions this process is cancelling or compensating right now */
    private array $settling = [];

    public function __construct(
        private readonly WorkflowRegistry $registry,
        private readonly WorkflowExecutor $executor,
        private readonly EngineSupport $support,
        private readonly WorkflowSettings $settings,
        private readonly WorkflowDispatcher $dispatcher,
        private readonly Container $container,
        private readonly int $batchSize = 10,
    ) {}

    public function registry(): WorkflowRegistry
    {
        return $this->registry;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, string>  $headers
     */
    public function start(string|WorkflowDefinition $workflow, array $input = [], array $headers = [], ?string $correlationId = null, ?string $parentCorrelationId = null): ExecutionState
    {
        if (! $this->settings->enabled) {
            throw new PatternDisabledException(ExecutionPattern::Workflow, 'firefly.orchestration.workflow.enabled');
        }

        $definition = is_string($workflow) ? $this->registry->get($workflow) : $workflow;
        $context = ExecutionContext::start(ExecutionPattern::Workflow, $definition->id, $input, $headers, $correlationId, $this->support->now());
        $context->setParentCorrelationId($parentCorrelationId);

        return $this->support->admission->admit(
            $definition->id,
            function () use ($definition, $context): ExecutionState {
                if ($definition->triggerMode === TriggerMode::Async) {
                    $pending = $this->support->checkpoint($context, ExecutionStatus::Pending);
                    $this->dispatcher->dispatch($context->correlationId);

                    return $this->support->persistence->findById($context->correlationId) ?? $pending;
                }

                return $this->support->lock->synchronized($context->correlationId, fn (): ExecutionState => $this->support->tracing->traceExecution(
                    $definition->id,
                    ExecutionPattern::Workflow,
                    $context->correlationId,
                    fn (): ExecutionState => $this->begin($definition, $context),
                ));
            },
            static fn (ExecutionState $state): bool => ! in_array($state->status, [ExecutionStatus::Failed, ExecutionStatus::TimedOut], true),
        );
    }

    /** The queued job's entry point: starts a PENDING execution; anything else is returned untouched. */
    public function run(string $correlationId): ExecutionState
    {
        return $this->support->lock->synchronized($correlationId, function () use ($correlationId): ExecutionState {
            $state = $this->load($correlationId);
            if ($state->status !== ExecutionStatus::Pending) {
                return $state;
            }

            $definition = $this->registry->get($state->executionName);
            $context = ExecutionContext::fromState($state);

            return $this->support->tracing->traceExecution($definition->id, ExecutionPattern::Workflow, $correlationId, fn (): ExecutionState => $this->begin($definition, $context));
        });
    }

    public function signal(string $correlationId, string $signal, mixed $payload = null): ExecutionState
    {
        return $this->support->lock->synchronized($correlationId, function () use ($correlationId, $signal, $payload): ExecutionState {
            $state = $this->load($correlationId);
            if ($state->isTerminal()) {
                throw new InvalidExecutionStateException("Workflow execution [{$correlationId}] is already {$state->status->value} and accepts no signals.");
            }

            $definition = $this->registry->get($state->executionName);
            $context = ExecutionContext::fromState($state);
            $context->receiveSignal($signal, $payload);
            $this->support->events->onSignalDelivered($definition->id, $correlationId, $signal);

            return $state->status === ExecutionStatus::Waiting
                ? $this->drive($definition, $context)
                : $this->support->checkpoint($context, $state->status);
        });
    }

    /** @return list<ExecutionState> the executions this tick advanced */
    public function resumeDue(): array
    {
        $advanced = [];
        foreach ($this->support->persistence->findDue($this->support->now(), $this->batchSize) as $due) {
            if ($due->pattern !== ExecutionPattern::Workflow) {
                continue;
            }

            try {
                $advanced[] = $this->support->lock->synchronized($due->correlationId, function () use ($due): ExecutionState {
                    $state = $this->load($due->correlationId);
                    if ($state->status !== ExecutionStatus::Waiting) {
                        return $state;
                    }

                    return $this->drive($this->registry->get($state->executionName), ExecutionContext::fromState($state));
                });
            } catch (ExecutionLockedException) {
                // Another process is advancing it right now; the next tick sees whatever it left.
            } catch (Throwable $error) {
                // One execution that cannot be driven (its definition was removed in a deploy, a handler blew up
                // outside a step) must not stop the rest of the batch: report it and move on to the next row.
                $this->support->logger->warning("Workflow [{$due->executionName}] execution [{$due->correlationId}] could not be resumed by the tick: {$error->getMessage()}", [
                    'exception' => $error,
                    'correlationId' => $due->correlationId,
                ]);
            }
        }

        return $advanced;
    }

    public function suspend(string $correlationId, string $reason = ''): ExecutionState
    {
        return $this->support->lock->synchronized($correlationId, function () use ($correlationId, $reason): ExecutionState {
            $state = $this->load($correlationId);
            if (! $state->status->canSuspend()) {
                throw new InvalidExecutionStateException("Workflow execution [{$correlationId}] is {$state->status->value} and cannot be suspended.");
            }

            $context = ExecutionContext::fromState($state);
            $context->setVariable('suspendedFrom', $state->status->value);
            $context->setVariable('suspendReason', $reason);
            $this->support->events->onWorkflowSuspended($state->executionName, $correlationId, $reason);

            return $this->support->checkpoint($context, ExecutionStatus::Suspended);
        });
    }

    public function resume(string $correlationId): ExecutionState
    {
        return $this->support->lock->synchronized($correlationId, function () use ($correlationId): ExecutionState {
            $state = $this->load($correlationId);
            if (! $state->status->canResume()) {
                throw new InvalidExecutionStateException("Workflow execution [{$correlationId}] is {$state->status->value}, not SUSPENDED.");
            }

            $definition = $this->registry->get($state->executionName);
            $context = ExecutionContext::fromState($state);
            $this->support->events->onWorkflowResumed($definition->id, $correlationId);

            return $context->variable('suspendedFrom') === ExecutionStatus::Pending->value
                ? $this->begin($definition, $context)
                : $this->drive($definition, $context);
        });
    }

    public function cancel(string $correlationId): ExecutionState
    {
        return $this->support->lock->synchronized($correlationId, function () use ($correlationId): ExecutionState {
            $state = $this->load($correlationId);
            if ($state->isTerminal()) {
                throw new InvalidExecutionStateException("Workflow execution [{$correlationId}] is already {$state->status->value}.");
            }

            $context = ExecutionContext::fromState($state);
            $cancelled = $this->settle($correlationId, function () use ($context, $state, $correlationId): ExecutionState {
                $this->cancelChildren($context);
                $cancelled = $this->support->checkpoint($context, ExecutionStatus::Cancelled, 'cancelled');
                $this->support->publish($this->support->lifecycle, OrchestrationEvent::executionCompleted($state->executionName, $correlationId, ExecutionPattern::Workflow, ExecutionStatus::Cancelled, $this->support->now()));
                $this->support->events->onCompleted($state->executionName, $correlationId, ExecutionPattern::Workflow, false, $this->support->elapsedMs($context));

                return $cancelled;
            });
            $this->notifyParent($cancelled);

            return $cancelled;
        });
    }

    public function compensate(string $correlationId): ExecutionState
    {
        return $this->support->lock->synchronized($correlationId, function () use ($correlationId): ExecutionState {
            $state = $this->load($correlationId);
            if ($state->isTerminal() && $state->status !== ExecutionStatus::Completed) {
                throw new InvalidExecutionStateException("Workflow execution [{$correlationId}] is already {$state->status->value} and has nothing left to compensate.");
            }

            $definition = $this->registry->get($state->executionName);
            $context = ExecutionContext::fromState($state);
            $this->settle($correlationId, function () use ($definition, $context): void {
                $this->cancelChildren($context);
                $this->executor->compensate($definition, $context);
            });

            return $this->finish($definition, $context, ExecutionStatus::Cancelled, 'compensated on request');
        });
    }

    /**
     * @param  list<mixed>  $arguments
     */
    public function query(string $correlationId, string $name, array $arguments = []): mixed
    {
        $state = $this->load($correlationId);
        $query = $this->registry->get($state->executionName)->query($name);
        $context = ExecutionContext::fromState($state);
        $invocation = $query->invocation;

        if ($invocation instanceof ClosureInvocation) {
            return ($invocation->closure)($context, ...$arguments);
        }
        if ($invocation instanceof MethodInvocation) {
            $bean = $this->container->make($invocation->class);
            $method = $invocation->method;
            if (is_object($bean) && method_exists($bean, $method)) {
                return $bean->{$method}(...($query->contextFirst ? [$context, ...$arguments] : $arguments));
            }
        }

        throw new OrchestrationException("Workflow query [{$name}] of [{$state->executionName}] cannot be invoked.", 'ORCHESTRATION_UNKNOWN_QUERY');
    }

    /**
     * @param  array<string, string|int|float|bool>  $attributes
     */
    public function upsertSearchAttributes(string $correlationId, array $attributes): ExecutionState
    {
        return $this->support->lock->synchronized($correlationId, function () use ($correlationId, $attributes): ExecutionState {
            $state = $this->load($correlationId);
            $context = ExecutionContext::fromState($state);
            foreach ($attributes as $key => $value) {
                $context->setSearchAttribute($key, $value);
            }

            return $this->support->checkpoint($context, $state->status, $state->failureReason, null);
        });
    }

    /**
     * @param  array<string, string|int|float|bool>  $criteria
     * @return list<ExecutionState>
     */
    public function search(array $criteria, int $limit = 100): array
    {
        return array_values(array_filter(
            $this->support->persistence->findBySearchAttributes($criteria, $limit),
            static fn (ExecutionState $state): bool => $state->pattern === ExecutionPattern::Workflow,
        ));
    }

    public function find(string $correlationId): ?ExecutionState
    {
        $state = $this->support->persistence->findById($correlationId);

        return $state !== null && $state->pattern === ExecutionPattern::Workflow ? $state : null;
    }

    /** @return list<ExecutionState> */
    public function findByStatus(ExecutionStatus $status, int $limit = 50): array
    {
        return $this->support->persistence->find(new ExecutionCriteria(ExecutionPattern::Workflow, $status, limit: $limit));
    }

    private function begin(WorkflowDefinition $workflow, ExecutionContext $context): ExecutionState
    {
        $this->support->checkpoint($context, ExecutionStatus::Running);
        $this->support->publish($this->support->lifecycle, OrchestrationEvent::executionStarted($workflow->id, $context->correlationId, ExecutionPattern::Workflow, $this->support->now()));
        $this->support->events->onStart($workflow->id, $context->correlationId, ExecutionPattern::Workflow);

        return $this->drive($workflow, $context);
    }

    private function drive(WorkflowDefinition $workflow, ExecutionContext $context): ExecutionState
    {
        $this->driving[$context->correlationId] = true;
        try {
            $context->setDeadline($workflow->timeoutMs > 0 ? $context->startedAt()->modify(sprintf('+%d milliseconds', $workflow->timeoutMs)) : null);

            while (true) {
                $outcome = $this->executor->run($workflow, $context);

                if ($outcome->kind === WorkflowRunOutcome::CHILD && $this->startChild($workflow, $context, (string) $outcome->stepId, $outcome->childInput)) {
                    continue;
                }

                return match ($outcome->kind) {
                    WorkflowRunOutcome::CHILD, WorkflowRunOutcome::WAITING => $this->support->checkpoint($context, ExecutionStatus::Waiting),
                    WorkflowRunOutcome::CONTINUE => $this->continueAsNew($workflow, $context, $outcome),
                    WorkflowRunOutcome::FAILED => $this->fail($workflow, $context, $outcome),
                    default => $this->complete($workflow, $context),
                };
            }
        } finally {
            unset($this->driving[$context->correlationId]);
        }
    }

    /**
     * Starts the child of `$stepId`; true when the step is already settled (the child finished inline, or the
     * parent does not wait for it) and the drive can go on, false when the parent now waits for the child.
     *
     * @param  array<string, mixed>  $input
     */
    private function startChild(WorkflowDefinition $workflow, ExecutionContext $context, string $stepId, array $input): bool
    {
        $child = $workflow->step($stepId)->child;
        if ($child === null) {
            return true;
        }

        $childState = $this->start($child->workflowId, $input, $context->headers(), null, $context->correlationId);
        $context->setChild($stepId, $childState->correlationId);
        $this->support->events->onChildWorkflowStarted($workflow->id, $context->correlationId, $child->workflowId, $childState->correlationId);

        if (! $child->waitForCompletion) {
            $this->executor->finishStep($workflow, $context, $stepId, ['correlationId' => $childState->correlationId, 'status' => $childState->status->value]);

            return true;
        }

        if ($childState->isTerminal()) {
            $this->executor->completeChild($workflow, $context, $stepId, $childState);

            return true;
        }

        if ($child->timeoutMs > 0) {
            $context->scheduleTimer("{$stepId}:child-timeout", $this->support->now()->modify(sprintf('+%d milliseconds', $child->timeoutMs)));
        }
        $context->setStepStatus($stepId, StepStatus::Waiting);
        $context->setWaitingOn($stepId);

        return false;
    }

    private function continueAsNew(WorkflowDefinition $workflow, ExecutionContext $context, WorkflowRunOutcome $outcome): ExecutionState
    {
        $next = $outcome->continueAsNew ?? new ContinueAsNew;
        $newId = Str::uuid()->toString();
        $context->setVariable('continuedAs', $newId);
        $this->finish($workflow, $context, ExecutionStatus::Completed, null, notify: false);
        $this->support->events->onContinueAsNew($workflow->id, $context->correlationId, $newId);

        $headers = $context->headers();
        $headers[self::CONTINUED_FROM_HEADER] ??= $context->correlationId;

        return $this->start($workflow, $next->input, $headers, $newId, $context->parentCorrelationId());
    }

    private function complete(WorkflowDefinition $workflow, ExecutionContext $context): ExecutionState
    {
        return $this->finish($workflow, $context, ExecutionStatus::Completed, null);
    }

    private function fail(WorkflowDefinition $workflow, ExecutionContext $context, WorkflowRunOutcome $outcome): ExecutionState
    {
        $error = $outcome->error ?? new OrchestrationException("Workflow [{$workflow->id}] failed.");
        $stepId = $outcome->stepId === '' ? null : $outcome->stepId;

        $this->support->checkpoint($context, ExecutionStatus::Compensating, $error->getMessage());
        $this->cancelChildren($context);
        $this->executor->compensate($workflow, $context);
        $this->support->deadLetter($context, $stepId, $outcome->timedOut ? ExecutionStatus::TimedOut : ExecutionStatus::Failed, $error);

        $suppressed = false;
        foreach ($workflow->onError as $callback) {
            if ($callback->suppressError && $callback->matchesError($error) && $callback->matchesStep($stepId)) {
                $suppressed = true;
            }
        }

        $status = $suppressed ? ExecutionStatus::Completed : ($outcome->timedOut ? ExecutionStatus::TimedOut : ExecutionStatus::Failed);
        $state = $this->finish($workflow, $context, $status, $suppressed ? null : $error->getMessage(), callbacks: false);
        $this->support->callbacks->invoke($workflow->onError, $context, $state, $error, $stepId);

        return $state;
    }

    private function finish(WorkflowDefinition $workflow, ExecutionContext $context, ExecutionStatus $status, ?string $reason, bool $notify = true, bool $callbacks = true): ExecutionState
    {
        $now = $this->support->now();
        $report = ExecutionReportBuilder::fromContext($context, $status, $reason, $now, $workflow->stepIds());
        $state = $this->support->checkpoint($context, $status, $reason, $report);

        $this->support->publish($this->support->lifecycle, OrchestrationEvent::executionCompleted($workflow->id, $context->correlationId, ExecutionPattern::Workflow, $status, $now));
        $this->support->events->onCompleted($workflow->id, $context->correlationId, ExecutionPattern::Workflow, $status->isSuccess(), $this->support->elapsedMs($context));

        if ($callbacks && $status === ExecutionStatus::Completed) {
            $this->support->callbacks->invoke($workflow->onComplete, $context, $state);
        }
        if ($notify) {
            $this->notifyParent($state);
        }

        return $state;
    }

    private function notifyParent(ExecutionState $child): void
    {
        $parentId = $child->parentCorrelationId;
        if ($parentId === null || isset($this->driving[$parentId]) || isset($this->settling[$parentId])) {
            return;
        }

        $this->support->lock->synchronized($parentId, function () use ($parentId, $child): void {
            $parent = $this->support->persistence->findById($parentId);
            if ($parent === null || $parent->status !== ExecutionStatus::Waiting) {
                return;
            }

            $key = $child->headers[self::CONTINUED_FROM_HEADER] ?? $child->correlationId;
            $stepId = array_search($key, $parent->children, true);
            if (! is_string($stepId) || $parent->waitingOn !== $stepId) {
                return;
            }

            $definition = $this->registry->get($parent->executionName);
            $context = ExecutionContext::fromState($parent);
            $this->executor->completeChild($definition, $context, $stepId, $child);
            $this->drive($definition, $context);
        });
    }

    /**
     * Runs `$work` with `$correlationId` marked as settling, so a child it cancels does not wake it back up
     * through notifyParent(): the lock is reentrant, and the parent is still WAITING on that child until
     * `$work` has moved it on.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function settle(string $correlationId, callable $work): mixed
    {
        $this->settling[$correlationId] = true;
        try {
            return $work();
        } finally {
            unset($this->settling[$correlationId]);
        }
    }

    private function cancelChildren(ExecutionContext $context): void
    {
        foreach ($context->children() as $childId) {
            $child = $this->support->persistence->findById($childId);
            if ($child !== null && ! $child->isTerminal()) {
                $this->cancel($childId);
            }
        }
    }

    private function load(string $correlationId): ExecutionState
    {
        $state = $this->support->persistence->findById($correlationId);
        if ($state === null || $state->pattern !== ExecutionPattern::Workflow) {
            throw new ExecutionNotFoundException($correlationId);
        }

        return $state;
    }
}
