<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tests\Support;

use Firefly\Orchestration\Persistence\ExecutionState;
use Firefly\Orchestration\Workflow\Engine\WorkflowEngine;
use Firefly\Orchestration\Workflow\Queue\WorkflowDispatcher;

/** Holds ASYNC starts instead of queueing them; runAll() plays the queue worker. */
final class RecordingWorkflowDispatcher implements WorkflowDispatcher
{
    /** @var list<string> */
    public array $dispatched = [];

    public function dispatch(string $correlationId): void
    {
        $this->dispatched[] = $correlationId;
    }

    /** @return list<ExecutionState> */
    public function runAll(WorkflowEngine $engine): array
    {
        $states = [];
        while ($this->dispatched !== []) {
            $states[] = $engine->run(array_shift($this->dispatched));
        }

        return $states;
    }
}
