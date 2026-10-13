<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Workflow\Queue;

use Illuminate\Contracts\Bus\Dispatcher;

/**
 * Dispatches a RunWorkflowJob on `firefly.orchestration.workflow.queue.connection` / `.name` (null = the
 * application's defaults). The job carries only the correlation id; the worker reads the execution from the
 * persistence provider, so ASYNC workflows need `cache` or `database` persistence — `memory` lives in the
 * process that dispatched, not in the worker (the `sync` queue connection is the exception: it runs inline).
 */
final class BusWorkflowDispatcher implements WorkflowDispatcher
{
    public function __construct(
        private readonly Dispatcher $bus,
        private readonly ?string $connection = null,
        private readonly ?string $queue = null,
    ) {}

    public function dispatch(string $correlationId): void
    {
        $job = new RunWorkflowJob($correlationId);
        if ($this->connection !== null) {
            $job->onConnection($this->connection);
        }
        if ($this->queue !== null) {
            $job->onQueue($this->queue);
        }

        $this->bus->dispatch($job);
    }
}
