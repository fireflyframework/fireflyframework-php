<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tests\CapstoneFixtures;

use Firefly\Container\Attributes\Service;
use Firefly\Orchestration\Core\Argument\Input;
use Firefly\Orchestration\Workflow\Attributes\CompensationStep;
use Firefly\Orchestration\Workflow\Attributes\Workflow;
use Firefly\Orchestration\Workflow\Attributes\WorkflowStep;
use RuntimeException;

/**
 * A workflow undone by a separate #[CompensationStep] method: `book` buys a label, and when `dispatch` fails
 * the engine calls voidLabel() with the label `book` returned — the step result is the method's implicit
 * argument, exactly as with the inline `compensationMethod:` form.
 */
#[Service]
#[Workflow(id: 'shipment')]
final class ShipmentWorkflow
{
    public function __construct(private readonly Ledger $ledger) {}

    #[WorkflowStep(id: 'book')]
    public function book(#[Input('parcel')] string $parcel): string
    {
        $this->ledger->record("book {$parcel}");

        return "label-{$parcel}";
    }

    #[CompensationStep(compensates: 'book')]
    public function voidLabel(string $label): string
    {
        $this->ledger->record("void {$label}");

        return "voided {$label}";
    }

    #[WorkflowStep(id: 'dispatch', dependsOn: ['book'])]
    public function dispatch(#[Input('carrier')] string $carrier): string
    {
        if ($carrier === 'closed') {
            throw new RuntimeException("carrier {$carrier} refused the parcel");
        }
        $this->ledger->record("dispatch via {$carrier}");

        return 'dispatched';
    }
}
