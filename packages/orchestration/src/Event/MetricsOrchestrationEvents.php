<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Event;

use Firefly\Orchestration\Core\ExecutionPattern;
use Throwable;

/** The listener that turns hooks into OrchestrationMetrics calls (jafly OrchestrationMetrics, as a hook). */
final class MetricsOrchestrationEvents extends OrchestrationEventsAdapter
{
    public function __construct(private readonly OrchestrationMetrics $metrics) {}

    public function onStepSuccess(string $name, string $correlationId, string $stepId, int $attempts, int $latencyMs): void
    {
        $this->metrics->recordStep($name, $stepId, 'success', $attempts, $latencyMs / 1000);
    }

    public function onStepFailed(string $name, string $correlationId, string $stepId, Throwable $error, int $attempts): void
    {
        $this->metrics->recordStep($name, $stepId, 'failure', $attempts, 0.0);
    }

    public function onStepCompensated(string $name, string $correlationId, string $stepId): void
    {
        $this->metrics->recordCompensation($name, $stepId, 'compensated');
    }

    public function onStepCompensationFailed(string $name, string $correlationId, string $stepId, Throwable $error): void
    {
        $this->metrics->recordCompensation($name, $stepId, 'failed');
    }

    public function onCompleted(string $name, string $correlationId, ExecutionPattern $pattern, bool $success, int $durationMs): void
    {
        $this->metrics->recordExecution($name, $pattern, $success ? 'success' : 'failure', $durationMs / 1000);
    }

    public function onDeadLettered(string $name, string $correlationId, ?string $stepId, string $error): void
    {
        $this->metrics->recordDeadLetter($name);
    }
}
