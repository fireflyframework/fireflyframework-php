<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tests\Support;

use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Event\OrchestrationMetrics;

/** Records each metric as one readable line. */
final class RecordingOrchestrationMetrics implements OrchestrationMetrics
{
    /** @var list<string> */
    public array $records = [];

    public function recordExecution(string $name, ExecutionPattern $pattern, string $outcome, float $seconds): void
    {
        $this->records[] = "execution {$name} {$pattern->value} {$outcome}";
    }

    public function recordStep(string $name, string $stepId, string $outcome, int $attempts, float $seconds): void
    {
        $this->records[] = "step {$name}.{$stepId} {$outcome} x{$attempts}";
    }

    public function recordCompensation(string $name, string $stepId, string $outcome): void
    {
        $this->records[] = "compensation {$name}.{$stepId} {$outcome}";
    }

    public function recordDeadLetter(string $name): void
    {
        $this->records[] = "dead-letter {$name}";
    }
}
