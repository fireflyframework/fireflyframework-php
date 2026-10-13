<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Event;

use Firefly\Orchestration\Core\ExecutionPattern;

/**
 * The tracing seam (the CqrsTracing shape): one span per execution and one per step attempt. The default is
 * NoOpOrchestrationTracing; firefly/observability supplies TracerOrchestrationTracing when tracing is on, so
 * this package names no span and no tracer.
 */
interface OrchestrationTracing
{
    /**
     * @template T
     *
     * @param  callable(): T  $execution
     * @return T
     */
    public function traceExecution(string $name, ExecutionPattern $pattern, string $correlationId, callable $execution): mixed;

    /**
     * @template T
     *
     * @param  callable(): T  $step
     * @return T
     */
    public function traceStep(string $name, string $stepId, string $correlationId, callable $step): mixed;
}
