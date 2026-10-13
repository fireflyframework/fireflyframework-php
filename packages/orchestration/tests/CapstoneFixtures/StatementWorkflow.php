<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tests\CapstoneFixtures;

use Firefly\Container\Attributes\Service;
use Firefly\Orchestration\Core\Argument\Input;
use Firefly\Orchestration\Core\TriggerMode;
use Firefly\Orchestration\Workflow\Attributes\Workflow;
use Firefly\Orchestration\Workflow\Attributes\WorkflowStep;

/** An ASYNC workflow: every start is persisted PENDING and run by a RunWorkflowJob on the Laravel queue. */
#[Service]
#[Workflow(id: 'statement', triggerMode: TriggerMode::Async)]
final class StatementWorkflow
{
    public function __construct(private readonly Ledger $ledger) {}

    #[WorkflowStep(id: 'render')]
    public function render(#[Input('account')] string $account): string
    {
        $this->ledger->record("render {$account}");

        return "statement-{$account}";
    }

    #[WorkflowStep(id: 'deliver', dependsOn: ['render'])]
    public function deliver(#[Input('account')] string $account): string
    {
        $this->ledger->record("deliver {$account}");

        return 'delivered';
    }
}
