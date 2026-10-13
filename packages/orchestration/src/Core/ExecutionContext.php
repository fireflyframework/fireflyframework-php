<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Core;

use DateTimeImmutable;
use Firefly\Orchestration\Persistence\ExecutionState;
use Firefly\Orchestration\Persistence\StateSerializer;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The live, mutable state of one execution while an engine advances it — what a step can inject as
 * `ExecutionContext $ctx` and what `#[FromStep]`, `#[Header]`, `#[Variable]` and the other argument attributes
 * read. It is turned into an immutable ExecutionState at every checkpoint (toState) and rebuilt from one on
 * resume (fromState).
 *
 * Within one uninterrupted run the context keeps NATIVE values — a step that returns a DTO hands the same
 * object to the next step's #[FromStep]. Across a checkpoint only the normalized form survives (spec decision
 * D5), and a Throwable survives as its message, rebuilt as a RuntimeException: the class of an exception
 * raised in a process that no longer exists is not something a JSON row can promise to reproduce.
 *
 * `deadline` is NOT persisted: it is a workflow's startedAt + timeoutMs, recomputed from the definition every
 * time the engine drives it, and toState() only folds it into `wakeAt` so the tick wakes a waiting workflow
 * whose deadline passed.
 */
final class ExecutionContext
{
    /** @var array<string, mixed> */
    private array $variables = [];

    /** @var array<string, mixed> */
    private array $stepResults = [];

    /** @var array<string, StepStatus> */
    private array $stepStatuses = [];

    /** @var array<string, int> */
    private array $stepAttempts = [];

    /** @var array<string, int> */
    private array $stepLatenciesMs = [];

    /** @var array<string, DateTimeImmutable> */
    private array $stepStartedAt = [];

    /** @var array<string, mixed> */
    private array $stepInputs = [];

    /** @var list<string> */
    private array $completionOrder = [];

    /** @var array<string, mixed> */
    private array $compensationResults = [];

    /** @var array<string, Throwable> */
    private array $compensationErrors = [];

    /** @var array<string, Throwable> */
    private array $stepErrors = [];

    /** @var array<string, mixed> */
    private array $tryResults = [];

    private ?TccPhase $currentPhase = null;

    /** @var array<string, true> */
    private array $idempotencyKeys = [];

    /** @var list<list<string>> */
    private array $topologyLayers = [];

    /** @var array<string, string|int|float|bool> */
    private array $searchAttributes = [];

    /** @var array<string, mixed> */
    private array $signals = [];

    /** @var array<string, DateTimeImmutable> */
    private array $timers = [];

    /**
     * The instant each timer was last checked (timerFired()) in THIS run. Not persisted: it only tells
     * wakeAt() which due timers a check actually saw fire, and every run checks its own waits again.
     *
     * @var array<string, DateTimeImmutable>
     */
    private array $timerChecks = [];

    private ?string $waitingOn = null;

    private ?string $parentCorrelationId = null;

    /** @var array<string, string> */
    private array $children = [];

    private ?DateTimeImmutable $deadline = null;

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, string>  $headers
     */
    private function __construct(
        public readonly string $correlationId,
        public readonly string $executionName,
        public readonly ExecutionPattern $pattern,
        private readonly DateTimeImmutable $startedAt,
        private readonly array $input,
        private array $headers,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, string>  $headers
     */
    public static function start(ExecutionPattern $pattern, string $name, array $input, array $headers, ?string $correlationId, DateTimeImmutable $now): self
    {
        return new self($correlationId ?? Str::uuid()->toString(), $name, $pattern, $now, $input, $headers);
    }

    public static function fromState(ExecutionState $state): self
    {
        $context = new self($state->correlationId, $state->executionName, $state->pattern, $state->startedAt, $state->input, $state->headers);
        $context->variables = $state->variables;
        $context->stepResults = $state->stepResults;
        $context->stepStatuses = $state->stepStatuses;
        $context->stepAttempts = $state->stepAttempts;
        $context->stepLatenciesMs = $state->stepLatenciesMs;
        $context->stepInputs = $state->stepInputs;
        $context->completionOrder = $state->completionOrder;
        $context->compensationResults = $state->compensationResults;
        $context->compensationErrors = array_map(static fn (string $message): Throwable => new RuntimeException($message), $state->compensationErrors);
        $context->stepErrors = array_map(static fn (string $message): Throwable => new RuntimeException($message), $state->stepErrors);
        $context->tryResults = $state->tryResults;
        $context->currentPhase = $state->currentPhase;
        $context->idempotencyKeys = array_fill_keys($state->idempotencyKeys, true);
        $context->topologyLayers = $state->topologyLayers;
        $context->searchAttributes = $state->searchAttributes;
        $context->signals = $state->signals;
        $context->timers = array_map(static fn (string $at): DateTimeImmutable => ExecutionState::parseInstant($at), $state->timers);
        $context->waitingOn = $state->waitingOn;
        $context->parentCorrelationId = $state->parentCorrelationId;
        $context->children = $state->children;

        return $context;
    }

    /**
     * @param  array<string, mixed>|null  $report
     */
    public function toState(ExecutionStatus $status, DateTimeImmutable $now, ?string $failureReason = null, ?array $report = null): ExecutionState
    {
        /** @var array<string, mixed> $input */
        $input = StateSerializer::normalize($this->input, 'input');
        /** @var array<string, mixed> $variables */
        $variables = StateSerializer::normalize($this->variables, 'variables');
        /** @var array<string, mixed> $results */
        $results = StateSerializer::normalize($this->stepResults, 'stepResults');
        /** @var array<string, mixed> $stepInputs */
        $stepInputs = StateSerializer::normalize($this->stepInputs, 'stepInputs');
        /** @var array<string, mixed> $compensationResults */
        $compensationResults = StateSerializer::normalize($this->compensationResults, 'compensationResults');
        /** @var array<string, mixed> $tryResults */
        $tryResults = StateSerializer::normalize($this->tryResults, 'tryResults');
        /** @var array<string, mixed> $signals */
        $signals = StateSerializer::normalize($this->signals, 'signals');
        /** @var array<string, mixed>|null $normalizedReport */
        $normalizedReport = $report === null ? null : StateSerializer::normalize($report, 'report');

        return new ExecutionState(
            correlationId: $this->correlationId,
            executionName: $this->executionName,
            pattern: $this->pattern,
            status: $status,
            startedAt: $this->startedAt,
            updatedAt: $now,
            input: $input,
            headers: $this->headers,
            variables: $variables,
            stepResults: $results,
            stepStatuses: $this->stepStatuses,
            stepAttempts: $this->stepAttempts,
            stepLatenciesMs: $this->stepLatenciesMs,
            stepInputs: $stepInputs,
            completionOrder: $this->completionOrder,
            compensationResults: $compensationResults,
            compensationErrors: array_map(static fn (Throwable $error): string => $error->getMessage(), $this->compensationErrors),
            stepErrors: array_map(static fn (Throwable $error): string => $error->getMessage(), $this->stepErrors),
            tryResults: $tryResults,
            currentPhase: $this->currentPhase,
            idempotencyKeys: array_map('strval', array_keys($this->idempotencyKeys)),
            topologyLayers: $this->topologyLayers,
            failureReason: $failureReason,
            completedAt: $status->isTerminal() ? $now : null,
            wakeAt: $status === ExecutionStatus::Waiting ? $this->wakeAt($now) : null,
            searchAttributes: $this->searchAttributes,
            signals: $signals,
            timers: array_map(static fn (DateTimeImmutable $at): string => ExecutionState::instant($at), $this->timers),
            waitingOn: $this->waitingOn,
            parentCorrelationId: $this->parentCorrelationId,
            children: $this->children,
            report: $normalizedReport,
        );
    }

    public function startedAt(): DateTimeImmutable
    {
        return $this->startedAt;
    }

    /** @return array<string, mixed> */
    public function input(): array
    {
        return $this->input;
    }

    public function stepInput(string $stepId): mixed
    {
        return $this->stepInputs[$stepId] ?? null;
    }

    public function putStepInput(string $stepId, mixed $input): void
    {
        $this->stepInputs[$stepId] = $input;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function header(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }

    public function setHeader(string $name, string $value): void
    {
        $this->headers[$name] = $value;
    }

    /** @return array<string, mixed> */
    public function variables(): array
    {
        return $this->variables;
    }

    public function variable(string $name): mixed
    {
        return $this->variables[$name] ?? null;
    }

    public function hasVariable(string $name): bool
    {
        return array_key_exists($name, $this->variables);
    }

    public function setVariable(string $name, mixed $value): void
    {
        $this->variables[$name] = $value;
    }

    public function removeVariable(string $name): void
    {
        unset($this->variables[$name]);
    }

    /** @return array<string, mixed> */
    public function results(): array
    {
        return $this->stepResults;
    }

    public function result(string $stepId): mixed
    {
        return $this->stepResults[$stepId] ?? null;
    }

    public function hasResult(string $stepId): bool
    {
        return array_key_exists($stepId, $this->stepResults);
    }

    public function putResult(string $stepId, mixed $result): void
    {
        $this->stepResults[$stepId] = $result;
    }

    public function stepStatus(string $stepId): StepStatus
    {
        return $this->stepStatuses[$stepId] ?? StepStatus::Pending;
    }

    public function setStepStatus(string $stepId, StepStatus $status): void
    {
        $this->stepStatuses[$stepId] = $status;
    }

    /** @return array<string, StepStatus> */
    public function stepStatuses(): array
    {
        return $this->stepStatuses;
    }

    public function attempts(string $stepId): int
    {
        return $this->stepAttempts[$stepId] ?? 0;
    }

    public function recordAttempt(string $stepId): int
    {
        return $this->stepAttempts[$stepId] = $this->attempts($stepId) + 1;
    }

    public function latencyMs(string $stepId): int
    {
        return $this->stepLatenciesMs[$stepId] ?? 0;
    }

    public function setLatencyMs(string $stepId, int $milliseconds): void
    {
        $this->stepLatenciesMs[$stepId] = $milliseconds;
    }

    public function markStepStarted(string $stepId, DateTimeImmutable $at): void
    {
        $this->stepStartedAt[$stepId] = $at;
    }

    public function stepStartedAt(string $stepId): ?DateTimeImmutable
    {
        return $this->stepStartedAt[$stepId] ?? null;
    }

    /** @return list<string> */
    public function completionOrder(): array
    {
        return $this->completionOrder;
    }

    public function markCompleted(string $stepId): void
    {
        if (! in_array($stepId, $this->completionOrder, true)) {
            $this->completionOrder[] = $stepId;
        }
    }

    public function compensationResult(string $stepId): mixed
    {
        return $this->compensationResults[$stepId] ?? null;
    }

    /** @return array<string, mixed> */
    public function compensationResults(): array
    {
        return $this->compensationResults;
    }

    public function putCompensationResult(string $stepId, mixed $result): void
    {
        $this->compensationResults[$stepId] = $result;
    }

    public function compensationError(string $stepId): ?Throwable
    {
        return $this->compensationErrors[$stepId] ?? null;
    }

    /** @return array<string, Throwable> */
    public function compensationErrors(): array
    {
        return $this->compensationErrors;
    }

    public function putCompensationError(string $stepId, Throwable $error): void
    {
        $this->compensationErrors[$stepId] = $error;
    }

    public function stepError(string $stepId): ?Throwable
    {
        return $this->stepErrors[$stepId] ?? null;
    }

    /** @return array<string, Throwable> */
    public function stepErrors(): array
    {
        return $this->stepErrors;
    }

    public function putStepError(string $stepId, Throwable $error): void
    {
        $this->stepErrors[$stepId] = $error;
    }

    public function clearStepError(string $stepId): void
    {
        unset($this->stepErrors[$stepId]);
    }

    public function tryResult(string $participantId): mixed
    {
        return $this->tryResults[$participantId] ?? null;
    }

    /** @return array<string, mixed> */
    public function tryResults(): array
    {
        return $this->tryResults;
    }

    public function putTryResult(string $participantId, mixed $result): void
    {
        $this->tryResults[$participantId] = $result;
    }

    public function currentPhase(): ?TccPhase
    {
        return $this->currentPhase;
    }

    public function setCurrentPhase(?TccPhase $phase): void
    {
        $this->currentPhase = $phase;
    }

    public function hasIdempotencyKey(string $key): bool
    {
        return isset($this->idempotencyKeys[$key]);
    }

    public function addIdempotencyKey(string $key): void
    {
        $this->idempotencyKeys[$key] = true;
    }

    /** @return list<list<string>> */
    public function topologyLayers(): array
    {
        return $this->topologyLayers;
    }

    /** @param list<list<string>> $layers */
    public function setTopologyLayers(array $layers): void
    {
        $this->topologyLayers = $layers;
    }

    /** @return array<string, string|int|float|bool> */
    public function searchAttributes(): array
    {
        return $this->searchAttributes;
    }

    public function setSearchAttribute(string $key, string|int|float|bool $value): void
    {
        $this->searchAttributes[$key] = $value;
    }

    /** @return array<string, mixed> */
    public function signals(): array
    {
        return $this->signals;
    }

    public function hasSignal(string $name): bool
    {
        return array_key_exists($name, $this->signals);
    }

    public function receiveSignal(string $name, mixed $payload): void
    {
        $this->signals[$name] = $payload;
    }

    public function consumeSignal(string $name): mixed
    {
        $payload = $this->signals[$name] ?? null;
        unset($this->signals[$name]);

        return $payload;
    }

    /** @return array<string, DateTimeImmutable> */
    public function timers(): array
    {
        return $this->timers;
    }

    public function hasTimer(string $timerId): bool
    {
        return isset($this->timers[$timerId]);
    }

    public function scheduleTimer(string $timerId, DateTimeImmutable $fireAt): void
    {
        $this->timers[$timerId] = $fireAt;
        unset($this->timerChecks[$timerId]);
    }

    public function timerFired(string $timerId, DateTimeImmutable $now): bool
    {
        if (! isset($this->timers[$timerId])) {
            return false;
        }
        $this->timerChecks[$timerId] = $now;

        return $this->timers[$timerId] <= $now;
    }

    public function removeTimer(string $timerId): void
    {
        unset($this->timers[$timerId], $this->timerChecks[$timerId]);
    }

    public function waitingOn(): ?string
    {
        return $this->waitingOn;
    }

    public function setWaitingOn(?string $stepId): void
    {
        $this->waitingOn = $stepId;
    }

    public function parentCorrelationId(): ?string
    {
        return $this->parentCorrelationId;
    }

    public function setParentCorrelationId(?string $correlationId): void
    {
        $this->parentCorrelationId = $correlationId;
    }

    /** @return array<string, string> */
    public function children(): array
    {
        return $this->children;
    }

    public function child(string $stepId): ?string
    {
        return $this->children[$stepId] ?? null;
    }

    public function setChild(string $stepId, string $childCorrelationId): void
    {
        $this->children[$stepId] = $childCorrelationId;
    }

    public function deadline(): ?DateTimeImmutable
    {
        return $this->deadline;
    }

    public function setDeadline(?DateTimeImmutable $deadline): void
    {
        $this->deadline = $deadline;
    }

    /**
     * The earliest PENDING instant. A timer a check of this run saw fire is being handled by the run that
     * checkpoints now (the step is still waiting on something else — a signal of a WaitForAll), and a due timer
     * nothing in this run checked belongs to a run that did not get that far (a tick back-off), so counting
     * either would make the tick wake this execution on every pass for nothing. A timer is judged against the
     * instant it was CHECKED, not the checkpoint instant: a wait is evaluated a moment before the checkpoint is
     * written, and a timer that came due in between was never seen firing — dropping it would leave a WAITING
     * execution with no wake-up, which no tick ever looks at again. Its wakeAt is then already past, and the
     * next tick drives it.
     */
    private function wakeAt(DateTimeImmutable $now): ?DateTimeImmutable
    {
        $candidates = [];
        foreach ($this->timers as $timerId => $at) {
            if ($at > ($this->timerChecks[$timerId] ?? $now)) {
                $candidates[] = $at;
            }
        }
        if ($this->deadline !== null) {
            $candidates[] = $this->deadline;
        }

        return $candidates === [] ? null : min($candidates);
    }
}
