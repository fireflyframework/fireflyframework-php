<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Workflow\Queue;

use Firefly\Orchestration\Workflow\Engine\WorkflowEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Runs one ASYNC workflow execution in a queue worker. Delivering it twice is harmless: WorkflowEngine::run()
 * only starts an execution that is still PENDING, under its lock.
 */
final class RunWorkflowJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $correlationId) {}

    public function handle(WorkflowEngine $engine): void
    {
        $engine->run($this->correlationId);
    }
}
