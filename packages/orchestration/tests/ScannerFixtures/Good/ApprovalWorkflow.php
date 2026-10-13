<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tests\ScannerFixtures\Good;

use Firefly\Orchestration\Core\Argument\Variable;
use Firefly\Orchestration\Core\ExecutionContext;
use Firefly\Orchestration\Core\TriggerMode;
use Firefly\Orchestration\Workflow\Attributes\ChildWorkflow;
use Firefly\Orchestration\Workflow\Attributes\OnWorkflowError;
use Firefly\Orchestration\Workflow\Attributes\ScheduledWorkflow;
use Firefly\Orchestration\Workflow\Attributes\WaitForAny;
use Firefly\Orchestration\Workflow\Attributes\WaitForSignal;
use Firefly\Orchestration\Workflow\Attributes\WaitForTimer;
use Firefly\Orchestration\Workflow\Attributes\Workflow;
use Firefly\Orchestration\Workflow\Attributes\WorkflowQuery;
use Firefly\Orchestration\Workflow\Attributes\WorkflowStep;
use Throwable;

#[Workflow(id: 'approval', triggerMode: TriggerMode::Async, maxRetries: 1, retryDelayMs: 10)]
#[ScheduledWorkflow(fixedRate: 60000)]
final class ApprovalWorkflow
{
    /** @param array<string, mixed> $input */
    #[WorkflowStep(id: 'submit', outputEventType: 'approval.submitted', compensatable: true, compensationMethod: 'withdraw')]
    public function submit(array $input): string
    {
        return 'submitted';
    }

    public function withdraw(string $result): void {}

    #[WorkflowStep(id: 'decide', dependsOn: ['submit'], maxRetries: 0, condition: 'input["amount"] > 100')]
    #[WaitForAny(signals: [new WaitForSignal('approved', 86400000)], timers: [new WaitForTimer(3600000, 'escalate')])]
    public function decide(#[Variable('signals.approved')] mixed $approval): string
    {
        return 'decided';
    }

    /** @return array{subject: string} */
    #[WorkflowStep(id: 'audit', dependsOn: ['submit'])]
    #[ChildWorkflow(workflowId: 'audit-trail', timeoutMs: 1000)]
    public function audit(): array
    {
        return ['subject' => 'x'];
    }

    #[WorkflowQuery('status')]
    public function status(ExecutionContext $ctx, string $format = 'short'): string
    {
        return 'ok';
    }

    #[OnWorkflowError(stepIds: ['decide'], suppressError: true)]
    public function onDecideError(Throwable $error, ExecutionContext $ctx): void {}
}
