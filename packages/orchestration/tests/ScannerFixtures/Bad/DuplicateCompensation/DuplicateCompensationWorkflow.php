<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tests\ScannerFixtures\Bad\DuplicateCompensation;

use Firefly\Orchestration\Workflow\Attributes\CompensationStep;
use Firefly\Orchestration\Workflow\Attributes\Workflow;
use Firefly\Orchestration\Workflow\Attributes\WorkflowStep;

#[Workflow(id: 'twice-undone')]
final class DuplicateCompensationWorkflow
{
    #[WorkflowStep(id: 'book')]
    public function book(): string
    {
        return 'label';
    }

    #[CompensationStep(compensates: 'book')]
    public function voidLabel(string $label): void {}

    #[CompensationStep(compensates: 'book')]
    public function voidAgain(string $label): void {}
}
