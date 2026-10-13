<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Core;

/**
 * How a workflow start runs: SYNC drives it inline until it completes or first waits; ASYNC persists it as
 * PENDING and dispatches a RunWorkflowJob onto the Laravel queue (firefly.orchestration.workflow.queue.*).
 */
enum TriggerMode: string
{
    case Sync = 'SYNC';
    case Async = 'ASYNC';
}
