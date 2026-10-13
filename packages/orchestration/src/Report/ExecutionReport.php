<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Report;

use DateTimeImmutable;
use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Core\StepStatus;
use Firefly\Orchestration\Persistence\ExecutionState;

/**
 * What an execution did, step by step (jafly ExecutionReport): built when it ends, attached to the result
 * object, stored with the final checkpoint and shown by the actuator endpoint and firefly:orchestration:show.
 */
final readonly class ExecutionReport
{
    /**
     * @param  array<string, StepReport>  $steps
     * @param  list<list<string>>  $layers
     */
    public function __construct(
        public string $name,
        public string $correlationId,
        public ExecutionPattern $pattern,
        public ExecutionStatus $status,
        public DateTimeImmutable $startedAt,
        public DateTimeImmutable $completedAt,
        public int $durationMs,
        public array $steps,
        public ?string $failureReason = null,
        public array $layers = [],
    ) {}

    /** @return list<string> */
    public function failedSteps(): array
    {
        return array_map('strval', array_keys(array_filter($this->steps, static fn (StepReport $step): bool => $step->status->isFailure())));
    }

    /** @return list<string> */
    public function compensatedSteps(): array
    {
        return array_map('strval', array_keys(array_filter($this->steps, static fn (StepReport $step): bool => $step->compensated || $step->status === StepStatus::Compensated)));
    }

    /**
     * @return array{name: string, correlationId: string, pattern: string, status: string, startedAt: string, completedAt: string, durationMs: int, failureReason: ?string, layers: list<list<string>>, steps: array<string, array{stepId: string, status: string, attempts: int, latencyMs: int, error: ?string, compensated: bool, compensationError: ?string}>}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'correlationId' => $this->correlationId,
            'pattern' => $this->pattern->value,
            'status' => $this->status->value,
            'startedAt' => ExecutionState::instant($this->startedAt),
            'completedAt' => ExecutionState::instant($this->completedAt),
            'durationMs' => $this->durationMs,
            'failureReason' => $this->failureReason,
            'layers' => $this->layers,
            'steps' => array_map(static fn (StepReport $step): array => $step->toArray(), $this->steps),
        ];
    }
}
