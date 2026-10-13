<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Saga\Engine;

use DateTimeImmutable;
use Firefly\Orchestration\Core\ExecutionContext;
use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Core\StepStatus;
use Firefly\Orchestration\Persistence\ExecutionState;
use Firefly\Orchestration\Report\ExecutionReport;
use Firefly\Orchestration\Saga\Registry\SagaDefinition;
use Throwable;

/**
 * What SagaEngine::execute() returns (jafly SagaResult). A failed step is reported here, never thrown: check
 * isSuccess(), then failedSteps()/compensatedSteps()/error. `success` is false for a FAILED saga and true for
 * a COMPLETED one — including one whose error an #[OnSagaError(suppressError: true)] suppressed.
 */
final readonly class SagaResult
{
    /**
     * @param  array<string, StepOutcome>  $steps
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public string $sagaName,
        public string $correlationId,
        public ExecutionStatus $status,
        public bool $success,
        public ?Throwable $error,
        public array $steps,
        public array $headers,
        public ?ExecutionReport $report,
        public DateTimeImmutable $startedAt,
        public DateTimeImmutable $completedAt,
    ) {}

    public static function from(SagaDefinition $saga, ExecutionContext $context, ExecutionStatus $status, ?Throwable $error, ?ExecutionReport $report, DateTimeImmutable $now): self
    {
        $steps = [];
        foreach ($saga->stepIds() as $stepId) {
            $steps[$stepId] = new StepOutcome(
                status: $context->stepStatus($stepId),
                attempts: $context->attempts($stepId),
                latencyMs: $context->latencyMs($stepId),
                result: $context->result($stepId),
                error: $context->stepError($stepId),
                compensated: $context->stepStatus($stepId) === StepStatus::Compensated,
                compensationResult: $context->compensationResult($stepId),
                compensationError: $context->compensationError($stepId),
            );
        }

        return new self($saga->name, $context->correlationId, $status, $status->isSuccess(), $error, $steps, $context->headers(), $report, $context->startedAt(), $now);
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    /** @return list<string> */
    public function failedSteps(): array
    {
        return array_map('strval', array_keys(array_filter($this->steps, static fn (StepOutcome $step): bool => $step->status->isFailure() || $step->error !== null)));
    }

    /** @return list<string> */
    public function compensatedSteps(): array
    {
        return array_map('strval', array_keys(array_filter($this->steps, static fn (StepOutcome $step): bool => $step->compensated)));
    }

    public function firstErrorStepId(): ?string
    {
        return $this->failedSteps()[0] ?? null;
    }

    public function resultOf(string $stepId): mixed
    {
        return ($this->steps[$stepId] ?? null)?->result;
    }

    /** @return array<string, mixed> */
    public function stepResults(): array
    {
        $results = [];
        foreach ($this->steps as $stepId => $step) {
            if ($step->result !== null) {
                $results[$stepId] = $step->result;
            }
        }

        return $results;
    }

    public function durationMs(): int
    {
        return max(0, (int) round(((float) $this->completedAt->format('U.u') - (float) $this->startedAt->format('U.u')) * 1000));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'sagaName' => $this->sagaName,
            'correlationId' => $this->correlationId,
            'status' => $this->status->value,
            'success' => $this->success,
            'error' => $this->error?->getMessage(),
            'startedAt' => ExecutionState::instant($this->startedAt),
            'completedAt' => ExecutionState::instant($this->completedAt),
            'durationMs' => $this->durationMs(),
            'headers' => $this->headers,
            'steps' => array_map(static fn (StepOutcome $step): array => $step->toArray(), $this->steps),
            'report' => $this->report?->toArray(),
        ];
    }
}
