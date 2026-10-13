<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Event;

use Firefly\Orchestration\Core\ExecutionPattern;

/** Runs the callable untraced — the default until firefly/observability's tracer adapter is wired. */
final class NoOpOrchestrationTracing implements OrchestrationTracing
{
    public function traceExecution(string $name, ExecutionPattern $pattern, string $correlationId, callable $execution): mixed
    {
        return $execution();
    }

    public function traceStep(string $name, string $stepId, string $correlationId, callable $step): mixed
    {
        return $step();
    }
}
