<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Recovery;

use Firefly\Orchestration\Workflow\Engine\WorkflowEngine;

/**
 * The timer tick (`firefly:orchestration:tick`, and a ScheduledDescriptor under lock LOCK every
 * `workflow.tick.interval`): resumes WAITING workflows whose timer, signal timeout, child timeout or deadline
 * is due. Returns how many it advanced.
 */
final class OrchestrationTickTask
{
    public const string LOCK = 'firefly:orchestration:tick';

    public function __construct(private readonly WorkflowEngine $workflows) {}

    public function tick(): int
    {
        return count($this->workflows->resumeDue());
    }
}
