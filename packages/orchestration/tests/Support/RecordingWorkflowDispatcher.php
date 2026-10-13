<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tests\Support;

use Firefly\Orchestration\Persistence\ExecutionState;
use Firefly\Orchestration\Workflow\Engine\WorkflowEngine;
use Firefly\Orchestration\Workflow\Queue\WorkflowDispatcher;
use Throwable;

/** Holds ASYNC starts instead of queueing them; runAll() plays the queue worker. */
final class RecordingWorkflowDispatcher implements WorkflowDispatcher
{
    /** @var list<string> */
    public array $dispatched = [];

    /** When set, the next dispatch throws it (a queue that is down) instead of recording the start. */
    public ?Throwable $failNext = null;

    public function dispatch(string $correlationId): void
    {
        if ($this->failNext !== null) {
            $error = $this->failNext;
            $this->failNext = null;

            throw $error;
        }

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
