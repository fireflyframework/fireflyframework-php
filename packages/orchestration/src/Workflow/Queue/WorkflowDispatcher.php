<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Workflow\Queue;

/** Hands a PENDING (ASYNC) workflow execution to whatever will run it — the Laravel queue in an application. */
interface WorkflowDispatcher
{
    public function dispatch(string $correlationId): void;
}
