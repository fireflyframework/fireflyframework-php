<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tests\ScannerFixtures\Bad\BothCompensationForms;

use Firefly\Orchestration\Workflow\Attributes\CompensationStep;
use Firefly\Orchestration\Workflow\Attributes\Workflow;
use Firefly\Orchestration\Workflow\Attributes\WorkflowStep;

#[Workflow(id: 'both-forms')]
final class BothCompensationFormsWorkflow
{
    #[WorkflowStep(id: 'book', compensatable: true, compensationMethod: 'voidLabel')]
    public function book(): string
    {
        return 'label';
    }

    public function voidLabel(string $label): void {}

    #[CompensationStep(compensates: 'book')]
    public function voidAgain(string $label): void {}
}
