<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tests\InvalidFixtures\UnknownChild;

use Firefly\Orchestration\Workflow\Attributes\ChildWorkflow;
use Firefly\Orchestration\Workflow\Attributes\Workflow;
use Firefly\Orchestration\Workflow\Attributes\WorkflowStep;

#[Workflow(id: 'orphan-parent')]
final class OrphanParent
{
    /** @return array<string, mixed> */
    #[WorkflowStep(id: 'spawn')]
    #[ChildWorkflow(workflowId: 'does-not-exist')]
    public function spawn(): array
    {
        return [];
    }
}
