<?php

declare(strict_types=1);

namespace Firefly\Observability\Orchestration;

use Firefly\Observability\Metrics\MetricsRecorder;
use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Event\OrchestrationMetrics;

/**
 * The real OrchestrationMetrics recorder — the CqrsMetrics seam again. Wins over the shipped
 * NoOpOrchestrationMetrics because ObservabilityAutoConfiguration (#[Order(500)]) registers it before
 * OrchestrationAutoConfiguration (#[Order(1000)]) evaluates its #[ConditionalOnMissingBean]. Tags are the
 * definition name, never the correlation id: a per-execution tag would make the series unbounded.
 */
final class MeterRegistryOrchestrationMetrics implements OrchestrationMetrics
{
    public function __construct(private readonly MetricsRecorder $recorder) {}

    public function recordExecution(string $name, ExecutionPattern $pattern, string $outcome, float $seconds): void
    {
        $this->recorder->record('orchestration_executions_seconds', ['name' => $name, 'pattern' => strtolower($pattern->value), 'outcome' => $outcome], $seconds);
    }

    public function recordStep(string $name, string $stepId, string $outcome, int $attempts, float $seconds): void
    {
        $this->recorder->record('orchestration_steps_seconds', ['name' => $name, 'step' => $stepId, 'outcome' => $outcome], $seconds);
    }

    public function recordCompensation(string $name, string $stepId, string $outcome): void
    {
        $this->recorder->increment('orchestration_compensations_total', ['name' => $name, 'step' => $stepId, 'outcome' => $outcome]);
    }

    public function recordDeadLetter(string $name): void
    {
        $this->recorder->increment('orchestration_dead_letters_total', ['name' => $name]);
    }
}
