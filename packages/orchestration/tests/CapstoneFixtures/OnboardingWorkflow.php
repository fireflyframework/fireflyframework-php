<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tests\CapstoneFixtures;

use Firefly\Container\Attributes\Service;
use Firefly\Orchestration\Core\Argument\Input;
use Firefly\Orchestration\Core\Argument\Variable;
use Firefly\Orchestration\Core\ExecutionContext;
use Firefly\Orchestration\Workflow\Attributes\WaitForSignal;
use Firefly\Orchestration\Workflow\Attributes\WaitForTimer;
use Firefly\Orchestration\Workflow\Attributes\Workflow;
use Firefly\Orchestration\Workflow\Attributes\WorkflowQuery;
use Firefly\Orchestration\Workflow\Attributes\WorkflowStep;

#[Service]
#[Workflow(id: 'onboarding')]
final class OnboardingWorkflow
{
    public function __construct(private readonly Ledger $ledger) {}

    #[WorkflowStep(id: 'register')]
    public function register(#[Input('email')] string $email, ExecutionContext $ctx): string
    {
        $ctx->setSearchAttribute('email', $email);
        $this->ledger->record("register {$email}");

        return "user-{$email}";
    }

    #[WorkflowStep(id: 'verify', dependsOn: ['register'])]
    #[WaitForSignal('email-verified')]
    public function verify(#[Variable('signals.email-verified')] mixed $proof): string
    {
        $this->ledger->record('verified');

        return 'verified';
    }

    #[WorkflowStep(id: 'cool-off', dependsOn: ['verify'])]
    #[WaitForTimer(delayMs: 1, timerId: 'cool-off')]
    public function coolOff(): string
    {
        $this->ledger->record('cooled off');

        return 'cooled';
    }

    #[WorkflowQuery('progress')]
    public function progress(ExecutionContext $ctx): int
    {
        return count($ctx->results());
    }
}
