<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Event;

use Firefly\Orchestration\Core\ExecutionPattern;

/** Records nothing — the default until firefly/observability's meter adapter is wired. */
final class NoOpOrchestrationMetrics implements OrchestrationMetrics
{
    public function recordExecution(string $name, ExecutionPattern $pattern, string $outcome, float $seconds): void {}

    public function recordStep(string $name, string $stepId, string $outcome, int $attempts, float $seconds): void {}

    public function recordCompensation(string $name, string $stepId, string $outcome): void {}

    public function recordDeadLetter(string $name): void {}
}
