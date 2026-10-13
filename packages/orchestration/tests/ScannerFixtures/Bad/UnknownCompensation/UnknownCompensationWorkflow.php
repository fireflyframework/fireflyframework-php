<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tests\ScannerFixtures\Bad\UnknownCompensation;

use Firefly\Orchestration\Workflow\Attributes\CompensationStep;
use Firefly\Orchestration\Workflow\Attributes\Workflow;
use Firefly\Orchestration\Workflow\Attributes\WorkflowStep;

#[Workflow(id: 'stray-undo')]
final class UnknownCompensationWorkflow
{
    #[WorkflowStep(id: 'book')]
    public function book(): string
    {
        return 'label';
    }

    #[CompensationStep(compensates: 'ship')]
    public function unship(): void {}
}
