<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Event;

use Firefly\Orchestration\Core\ExecutionPattern;

/**
 * The metrics seam (the CqrsMetrics shape). NoOpOrchestrationMetrics is the default; firefly/observability
 * supplies MeterRegistryOrchestrationMetrics, which records `orchestration_executions_seconds`,
 * `orchestration_steps_seconds`, `orchestration_compensations_total` and `orchestration_dead_letters_total`.
 * `outcome` is `success` / `failure` for executions and steps, `compensated` / `failed` for compensations.
 */
interface OrchestrationMetrics
{
    public function recordExecution(string $name, ExecutionPattern $pattern, string $outcome, float $seconds): void;

    public function recordStep(string $name, string $stepId, string $outcome, int $attempts, float $seconds): void;

    public function recordCompensation(string $name, string $stepId, string $outcome): void;

    public function recordDeadLetter(string $name): void;
}
