<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Tests\CapstoneFixtures\Ledger;
use Firefly\Orchestration\Tests\Support\OrchestrationCapstoneTestCase;
use Firefly\Orchestration\Workflow\Engine\WorkflowEngine;

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
