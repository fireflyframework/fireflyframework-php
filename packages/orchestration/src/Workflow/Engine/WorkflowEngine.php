<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Workflow\Engine;

use Firefly\Kernel\Exception\Infrastructure\DataAccessException;
use Firefly\Orchestration\Core\ClosureInvocation;
use Firefly\Orchestration\Core\EngineSupport;
use Firefly\Orchestration\Core\Exception\ExecutionLockedException;
use Firefly\Orchestration\Core\Exception\ExecutionNotFoundException;
use Firefly\Orchestration\Core\Exception\InvalidExecutionStateException;
use Firefly\Orchestration\Core\Exception\OrchestrationException;
use Firefly\Orchestration\Core\Exception\PatternDisabledException;
use Firefly\Orchestration\Core\Exception\StepTimeoutException;
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
use PDOException;
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

    /** The timer that holds a tick back-off (resumeDue()); it is only ever a wake-up, never a step's wait. */
    public const string BACKOFF_TIMER = 'tick:backoff';

    /** The variable counting the ticks in a row that could not drive an execution. */
    public const string BACKOFF_FAILURES = 'tickFailures';

    /** The variable compensate() records, so recover() finishes an interrupted compensation on request as one. */
    public const string COMPENSATION_REQUESTED = 'compensationRequested';

    private const int BACKOFF_BASE_SECONDS = 60;

    private const int BACKOFF_MAX_SECONDS = 3600;

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
        $this->refuseExisting($context->correlationId);

        return $this->support->admission->admit(
            $definition->id,
            function () use ($definition, $context): ExecutionState {
                if ($definition->triggerMode === TriggerMode::Async) {
                    // Under the lock like a SYNC start: the existence check and the PENDING upsert must be one
                    // step, or two starts racing for a caller-supplied id both pass the check, the second
                    // overwrites the first one's input, and both dispatch a job.
                    return $this->support->lock->synchronized($context->correlationId, function () use ($context): ExecutionState {
                        $this->refuseExisting($context->correlationId);
                        $pending = $this->support->checkpoint($context, ExecutionStatus::Pending);
                        $this->dispatcher->dispatch($context->correlationId);

                        return $this->support->persistence->findById($context->correlationId) ?? $pending;
                    });
                }

                return $this->support->lock->synchronized($context->correlationId, function () use ($definition, $context): ExecutionState {
                    $this->refuseExisting($context->correlationId);

                    return $this->support->tracing->traceExecution(
                        $definition->id,
                        ExecutionPattern::Workflow,
                        $context->correlationId,
                        fn (): ExecutionState => $this->begin($definition, $context),
                    );
                });
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

    /**
     * Drives every due WAITING workflow, `batchSize` rows at a time, earliest wake-up first.
     *
     * One execution that cannot be driven (its definition was removed in a deploy, or is not deployed on this
     * node yet; a handler blew up outside a step) is logged and backed off (backOff()): its wake-up moves
     * forward, so it leaves the head of the due queue instead of coming back first on every tick and, once a
     * batch of them is due, starving every healthy timer and deadline. A rolling deploy can leave a node that
     * does not know a workflow yet, so one failed attempt does not end the execution; the attempts are counted
     * in the state (BACKOFF_FAILURES), and the one that reaches `firefly.orchestration.workflow.
     * max-resume-attempts` parks it FAILED and dead-letters it (park()). When a batch had to back rows off, the
     * tick asks for the next batch, so the healthy rows behind them still move now.
     *
     * A database fault (a PDOException, which Laravel's QueryException is, or a firefly DataAccessException) is
     * not the execution's fault: it is neither counted nor swallowed, and escapes the tick so the scheduler sees
     * the outage instead of a log line per row.
     *
     * @return list<ExecutionState> the executions this tick advanced
     */
    public function resumeDue(): array
    {
        $advanced = [];
        $seen = [];
        do {
            $fresh = 0;
            $backedOff = false;
            foreach ($this->support->persistence->findDue($this->support->now(), $this->batchSize) as $due) {
                if (isset($seen[$due->correlationId])) {
                    continue;
                }
                $seen[$due->correlationId] = true;
                $fresh++;
                if ($due->pattern !== ExecutionPattern::Workflow) {
                    continue;
                }

                try {
                    $advanced[] = $this->support->lock->synchronized($due->correlationId, function () use ($due): ExecutionState {
                        $state = $this->load($due->correlationId);
                        if ($state->status !== ExecutionStatus::Waiting) {
                            return $state;
                        }

                        try {
                            $context = ExecutionContext::fromState($state);
                            $context->removeTimer(self::BACKOFF_TIMER);
                            $context->removeVariable(self::BACKOFF_FAILURES);

                            return $this->drive($this->registry->get($state->executionName), $context);
                        } catch (ExecutionLockedException|PDOException|DataAccessException $notItsFault) {
                            throw $notItsFault;
                        } catch (Throwable $error) {
                            $this->backOff($due->correlationId, $state, $error);

                            throw $error;
                        }
                    });
                } catch (ExecutionLockedException) {
                    // Another process is advancing it right now; the next tick sees whatever it left.
                } catch (PDOException|DataAccessException $fault) {
                    throw $fault;
                } catch (Throwable $error) {
                    $backedOff = true;
                    $this->logTickFailure("Workflow [{$due->executionName}] execution [{$due->correlationId}] could not be resumed by the tick", $due, $error);
                }
            }
        } while ($backedOff && $fresh > 0 && count($advanced) < $this->batchSize);

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

    /**
     * Re-drives a workflow a crashed process left in a recoverable status (the recovery sweep's `resume`
     * action): a PENDING one is begun, a RUNNING one is driven from its last checkpoint, so the step that was
     * running when the process died runs again — make workflow steps idempotent. A COMPENSATING one is never
     * driven forward, since its undo is already under way: the compensation is finished instead, over the steps
     * still DONE, and the workflow ends the way the interrupted settlement would have ended it (see
     * finishCompensation()).
     */
    public function recover(string $correlationId): ExecutionState
    {
        return $this->support->lock->synchronized($correlationId, function () use ($correlationId): ExecutionState {
            $state = $this->load($correlationId);
            if (! $state->status->isRecoverable()) {
                throw new InvalidExecutionStateException("Workflow execution [{$correlationId}] is {$state->status->value}; only an interrupted execution can be recovered.");
            }

            $definition = $this->registry->get($state->executionName);
            $context = ExecutionContext::fromState($state);

            return match ($state->status) {
                ExecutionStatus::Pending => $this->begin($definition, $context),
                ExecutionStatus::Compensating => $this->finishCompensation($definition, $context),
                default => $this->drive($definition, $context),
            };
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
            // Checkpointed COMPENSATING with the request recorded before anything is undone, so a process that
            // dies part-way leaves a row recover() finishes as a compensation on request, not as a failure.
            $context->setVariable(self::COMPENSATION_REQUESTED, true);
            $this->support->checkpoint($context, ExecutionStatus::Compensating);

            return $this->compensateOnRequest($definition, $context);
        });
    }

    private function compensateOnRequest(WorkflowDefinition $workflow, ExecutionContext $context): ExecutionState
    {
        $this->settle($context->correlationId, function () use ($workflow, $context): void {
            $this->cancelChildren($context);
            $this->executor->compensate($workflow, $context);
        });

        return $this->finish($workflow, $context, ExecutionStatus::Cancelled, 'compensated on request');
    }

    /**
     * Finishes the compensation of a workflow a crashed process left COMPENSATING. A compensation asked for
     * through compensate() is finished the same way and ends CANCELLED. Otherwise a step failure (or the
     * workflow's deadline) started it, and fail() settles it again from that failed step: the steps still DONE
     * are compensated, the failure is dead-lettered and the workflow ends FAILED (TIMED_OUT for a passed
     * deadline). Steps already COMPENSATED are not undone twice, and no PENDING step ever runs.
     */
    private function finishCompensation(WorkflowDefinition $workflow, ExecutionContext $context): ExecutionState
    {
        if ($context->variable(self::COMPENSATION_REQUESTED) === true) {
            return $this->compensateOnRequest($workflow, $context);
        }

        $outcome = null;
        foreach ($workflow->stepIds() as $stepId) {
            if (in_array($context->stepStatus($stepId), [StepStatus::Failed, StepStatus::TimedOut], true)) {
                $outcome = WorkflowRunOutcome::failed($stepId, $context->stepError($stepId) ?? new OrchestrationException("Workflow [{$workflow->id}] step [{$stepId}] failed."));

                break;
            }
        }

        $deadline = $context->deadline();
        if ($outcome === null && $deadline !== null && $this->support->now() >= $deadline) {
            $outcome = WorkflowRunOutcome::failed($context->waitingOn() ?? '', new StepTimeoutException("Workflow [{$workflow->id}] passed its timeoutMs deadline of {$workflow->timeoutMs} ms."), timedOut: true);
        }

        $outcome ??= WorkflowRunOutcome::failed('', new OrchestrationException("Workflow [{$workflow->id}] was interrupted while compensating."));

        return $this->settle($context->correlationId, fn (): ExecutionState => $this->fail($workflow, $context, $outcome));
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
            // The deadline is not persisted, only folded into wakeAt: without it a WAITING workflow's wake-up
            // would lose the deadline, and the tick would never time it out.
            if ($this->registry->has($state->executionName)) {
                $this->applyDeadline($this->registry->get($state->executionName), $context);
            }

            // Saved as is rather than through checkpoint(): the context does not carry the execution report,
            // and checkpointing a finished workflow without it would erase the report the upsert must keep.
            $updated = $context->toState($state->status, $this->support->now(), $state->failureReason, $state->report);
            $this->support->persistence->save($updated);

            return $updated;
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
            $this->applyDeadline($workflow, $context);

            while (true) {
                $outcome = $this->executor->run($workflow, $context);

                if ($outcome->kind === WorkflowRunOutcome::CHILD) {
                    $stepId = (string) $outcome->stepId;
                    try {
                        if ($this->startChild($workflow, $context, $stepId, $outcome->childInput)) {
                            continue;
                        }
                    } catch (Throwable $error) {
                        // The child could not be started at all (its definition is gone, the pattern is disabled,
                        // admission refused it, the ASYNC dispatch failed): that is this step failing, so the
                        // parent compensates, dead-letters and ends FAILED instead of staying RUNNING.
                        $outcome = $this->executor->failed($workflow, $context, $stepId, $error);
                    }
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

        // The child is a chain of its own: the parent's continue-as-new origin must not leak into it, or the
        // child's completion would be matched against the parent's original run id instead of its own.
        $headers = $context->headers();
        unset($headers[self::CONTINUED_FROM_HEADER]);

        $childId = Str::uuid()->toString();
        try {
            $childState = $this->start($child->workflowId, $input, $headers, $childId, $context->correlationId);
        } catch (Throwable $error) {
            // A child that got as far as its first checkpoint (an ASYNC dispatch that failed) is recorded, so
            // the parent's failure cancels it rather than leaving it PENDING with nobody waiting for it.
            if ($this->support->persistence->findById($childId) !== null) {
                $context->setChild($stepId, $childId);
            }

            throw $error;
        }
        // The id this parent generated, not $childState's: a child that continued as new inline returns its LAST
        // run, but its completion is matched on the origin (wakeParent() keys on CONTINUED_FROM_HEADER) and
        // WorkflowExecutor::settledChild() follows the continuedAs chain from it, so the origin is what stays
        // recorded; recording the last run would leave the parent waiting forever once that run waits.
        $context->setChild($stepId, $childId);
        $this->support->events->onChildWorkflowStarted($workflow->id, $context->correlationId, $child->workflowId, $childId);

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

        try {
            $this->wakeParent($parentId, $child);
        } catch (ExecutionLockedException $locked) {
            if ($locked->correlationId !== $parentId) {
                throw $locked;
            }
            // The child is already checkpointed terminal; failing it now would only fail its job, and a redelivery
            // is a no-op. Whoever holds the parent is driving it (a signal, the tick, a resume), and every drive of
            // a parent waiting on a child reads the child's persisted state first (WorkflowExecutor), so that
            // drive, or the next one, settles the step this wake-up could not.
            $this->support->logger->warning("Workflow execution [{$child->correlationId}] finished but could not wake its parent [{$parentId}]: {$locked->getMessage()}", [
                'exception' => $locked,
                'correlationId' => $child->correlationId,
                'parentCorrelationId' => $parentId,
            ]);
        }
    }

    private function wakeParent(string $parentId, ExecutionState $child): void
    {
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
            $child = $this->lastRun($childId);
            if ($child !== null && ! $child->isTerminal()) {
                $this->cancel($child->correlationId);
            }
        }
    }

    /**
     * The latest run of the chain that starts at `$correlationId`: a run that continued as new is COMPLETED and
     * points at its successor through the `continuedAs` variable, and the live run is the last one.
     */
    private function lastRun(string $correlationId): ?ExecutionState
    {
        $seen = [];
        $state = $this->support->persistence->findById($correlationId);
        while ($state !== null) {
            $next = $state->variables['continuedAs'] ?? null;
            if (! is_string($next) || $next === '' || isset($seen[$next])) {
                return $state;
            }
            $seen[$next] = true;
            $successor = $this->support->persistence->findById($next);
            if ($successor === null) {
                return $state;
            }
            $state = $successor;
        }

        return null;
    }

    private function applyDeadline(WorkflowDefinition $workflow, ExecutionContext $context): void
    {
        $context->setDeadline($workflow->timeoutMs > 0 ? $context->startedAt()->modify(sprintf('+%d milliseconds', $workflow->timeoutMs)) : null);
    }

    /**
     * Moves the wake-up of an execution the tick could not drive forward, doubling from BACKOFF_BASE_SECONDS up
     * to BACKOFF_MAX_SECONDS with every tick in a row that fails on it. It is written on the latest checkpoint
     * (the failed drive may have written one) and only while that is still WAITING; the deadline is left out of
     * the wake-up on purpose, since a deadline already past would keep the row at the head of the queue. The
     * next drive that gets through clears the back-off and recomputes the wake-up from the definition. The
     * attempt that reaches `max-resume-attempts` parks the execution instead (park()).
     */
    private function backOff(string $correlationId, ExecutionState $before, Throwable $cause): void
    {
        try {
            $state = $this->support->persistence->findById($correlationId) ?? $before;
            if ($state->status !== ExecutionStatus::Waiting) {
                return;
            }

            $previous = $before->variables[self::BACKOFF_FAILURES] ?? 0;
            $failures = (is_int($previous) ? $previous : 0) + 1;
            $context = ExecutionContext::fromState($state);
            $context->setVariable(self::BACKOFF_FAILURES, $failures);

            if ($failures >= $this->settings->maxResumeAttempts) {
                $this->park($state, $context, $cause, $failures);

                return;
            }

            $delay = min(self::BACKOFF_MAX_SECONDS, self::BACKOFF_BASE_SECONDS * 2 ** min($failures - 1, 16));
            $context->scheduleTimer(self::BACKOFF_TIMER, $this->support->now()->modify(sprintf('+%d seconds', $delay)));
            $this->support->checkpoint($context, ExecutionStatus::Waiting, $state->failureReason);
        } catch (Throwable $error) {
            $this->logTickFailure("Workflow [{$before->executionName}] execution [{$correlationId}] could not be backed off by the tick", $before, $error);
        }
    }

    /**
     * Logs a failure of the tick without the error's message at warning level: a message can carry what a
     * handler or a driver put in it (a query, a token, a payload), and warnings are what log shipping keeps. The
     * warning names the exception class, the execution and its correlation id; the message and the exception
     * itself go to debug, for whoever turns it on.
     */
    private function logTickFailure(string $what, ExecutionState $execution, Throwable $error): void
    {
        $context = ['correlationId' => $execution->correlationId, 'executionName' => $execution->executionName, 'exceptionClass' => $error::class];
        $this->support->logger->warning($what.' ('.$error::class.').', $context);
        $this->support->logger->debug($what.': '.$error->getMessage(), $context + ['exception' => $error]);
    }

    /**
     * Ends an execution the tick failed to drive `$attempts` ticks in a row: FAILED, with a report and no wake-up
     * (only a WAITING row has one), so it leaves the due queue for good; dead-lettered with the last error for an operator to retry,
     * and its parent woken like by any other failed child. Nothing is compensated: the definition may be gone,
     * and the steps it completed are named in the report and the dead letter.
     */
    private function park(ExecutionState $state, ExecutionContext $context, Throwable $cause, int $attempts): void
    {
        $reason = "Workflow [{$state->executionName}] could not be resumed by the tick after {$attempts} attempts (".$cause::class.'); parked FAILED.';
        $stepIds = $this->registry->has($state->executionName)
            ? $this->registry->get($state->executionName)->stepIds()
            : array_map('strval', array_keys($state->stepStatuses));
        $now = $this->support->now();
        $parked = $this->support->checkpoint($context, ExecutionStatus::Failed, $reason, ExecutionReportBuilder::fromContext($context, ExecutionStatus::Failed, $reason, $now, $stepIds));
        $this->support->deadLetter($context, $state->waitingOn, ExecutionStatus::Failed, $cause);
        $this->support->logger->warning($reason, ['correlationId' => $state->correlationId, 'exceptionClass' => $cause::class]);
        $this->support->publish($this->support->lifecycle, OrchestrationEvent::executionCompleted($state->executionName, $state->correlationId, ExecutionPattern::Workflow, ExecutionStatus::Failed, $now));
        $this->support->events->onCompleted($state->executionName, $state->correlationId, ExecutionPattern::Workflow, false, $this->support->elapsedMs($context));
        $this->notifyParent($parked);
    }

    /**
     * The first checkpoint is an upsert, so starting with a correlation id that is already taken would reset that
     * execution to RUNNING and run every step again (SagaEngine and TccEngine refuse it the same way).
     * Continue-as-new always takes a fresh id.
     */
    private function refuseExisting(string $correlationId): void
    {
        $state = $this->support->persistence->findById($correlationId);
        if ($state !== null) {
            throw new InvalidExecutionStateException("Execution [{$correlationId}] already exists ({$state->pattern->value} {$state->executionName}, {$state->status->value}); signal(), resume() or cancel() it instead of starting it again.");
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
