<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Core\StepStatus;
use Firefly\Orchestration\Persistence\OrchestrationSchema;
use Firefly\Orchestration\Tests\CapstoneFixtures\Ledger;
use Firefly\Orchestration\Tests\Support\OrchestrationCapstoneTestCase;
use Firefly\Orchestration\Workflow\Engine\WorkflowEngine;
use Illuminate\Support\Facades\DB;

uses(OrchestrationCapstoneTestCase::class);

it('parks a workflow on a signal and a timer in the database and finishes it from the signal and the tick', function (): void {
    /** @var OrchestrationCapstoneTestCase $this */
    $workflows = $this->app()->make(WorkflowEngine::class);

    $started = $workflows->start('onboarding', ['email' => 'ada@example.com'], correlationId: 'onb-1');
    $signalled = $workflows->signal('onb-1', 'email-verified', ['token' => 't-1']);
    usleep(5_000);
    $workflows->resumeDue();
    $finished = $workflows->find('onb-1');

    expect($started->status)->toBe(ExecutionStatus::Waiting)
        ->and($started->waitingOn)->toBe('verify')
        ->and($signalled->status)->toBe(ExecutionStatus::Waiting)
        ->and($signalled->waitingOn)->toBe('cool-off')
        ->and($finished?->status)->toBe(ExecutionStatus::Completed)
        ->and($this->app()->make(Ledger::class)->lines)->toBe(['hook start onboarding', 'register ada@example.com', 'verified', 'cooled off'])
        ->and($workflows->query('onb-1', 'progress'))->toBe(3)
        ->and(array_map(static fn ($s): string => $s->correlationId, $workflows->search(['email' => 'ada@example.com'])))->toBe(['onb-1']);
});

it('undoes a failed workflow through its #[CompensationStep] method, handing it the step result, and dead-letters the failure', function (): void {
    /** @var OrchestrationCapstoneTestCase $this */
    $workflows = $this->app()->make(WorkflowEngine::class);

    $failed = $workflows->start('shipment', ['parcel' => 'p-7', 'carrier' => 'closed'], correlationId: 'ship-1');
    $stored = $workflows->find('ship-1');

    expect($failed->status)->toBe(ExecutionStatus::Failed)
        ->and($failed->failureReason)->toBe('carrier closed refused the parcel')
        ->and($this->app()->make(Ledger::class)->lines)->toBe(['hook start shipment', 'book p-7', 'void label-p-7'])
        ->and($stored?->stepStatus('book'))->toBe(StepStatus::Compensated)
        ->and($stored?->compensationResults)->toBe(['book' => 'voided label-p-7'])
        ->and(DB::table(OrchestrationSchema::EXECUTIONS)->where('correlation_id', 'ship-1')->value('status'))->toBe('FAILED')
        ->and(DB::table(OrchestrationSchema::DEAD_LETTERS)->where('correlation_id', 'ship-1')->value('step_id'))->toBe('dispatch');
});

it('leaves the #[CompensationStep] method alone when the workflow completes', function (): void {
    /** @var OrchestrationCapstoneTestCase $this */
    $finished = $this->app()->make(WorkflowEngine::class)->start('shipment', ['parcel' => 'p-8', 'carrier' => 'post'], correlationId: 'ship-2');

    expect($finished->status)->toBe(ExecutionStatus::Completed)
        ->and($finished->stepStatus('book'))->toBe(StepStatus::Done)
        ->and($finished->compensationResults)->toBe([])
        ->and($this->app()->make(Ledger::class)->lines)->toBe(['hook start shipment', 'book p-8', 'dispatch via post']);
});
