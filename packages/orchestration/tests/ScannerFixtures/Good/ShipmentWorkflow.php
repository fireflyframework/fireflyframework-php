<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tests\ScannerFixtures\Good;

use Firefly\Orchestration\Core\Argument\Input;
use Firefly\Orchestration\Workflow\Attributes\CompensationStep;
use Firefly\Orchestration\Workflow\Attributes\Workflow;
use Firefly\Orchestration\Workflow\Attributes\WorkflowStep;

/** The separate-method compensation form: #[CompensationStep] marks the undo of `book`, which needs no compensatable: flag. */
#[Workflow(id: 'shipment')]
final class ShipmentWorkflow
{
    #[WorkflowStep(id: 'book')]
    public function book(#[Input('parcel')] string $parcel): string
    {
        return "label-{$parcel}";
    }

    #[CompensationStep(compensates: 'book')]
    public function voidLabel(string $label): void {}

    #[WorkflowStep(id: 'dispatch', dependsOn: ['book'])]
    public function dispatch(): string
    {
        return 'dispatched';
    }
}
