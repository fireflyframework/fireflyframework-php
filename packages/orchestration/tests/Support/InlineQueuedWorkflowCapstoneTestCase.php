<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tests\Support;

/**
 * The queued-workflow capstone with `firefly.orchestration.workflow.queue.connection` = `sync`: the one
 * connection on which an ASYNC start runs inline, inside the start, as BusWorkflowDispatcher documents.
 */
abstract class InlineQueuedWorkflowCapstoneTestCase extends QueuedWorkflowCapstoneTestCase
{
    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return [
            ...parent::configOverrides(),
            'firefly.orchestration.workflow.queue.connection' => 'sync',
        ];
    }
}
