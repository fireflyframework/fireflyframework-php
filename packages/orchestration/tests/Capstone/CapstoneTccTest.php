<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Core\Orchestrator;
use Firefly\Orchestration\Tests\CapstoneFixtures\Ledger;
use Firefly\Orchestration\Tests\Support\OrchestrationCapstoneTestCase;

uses(OrchestrationCapstoneTestCase::class);

it('confirms an attribute-discovered TCC across its participant beans', function (): void {
    /** @var OrchestrationCapstoneTestCase $this */
    $outcome = $this->app()->make(Orchestrator::class)->start(ExecutionPattern::Tcc, 'transfer', ['amount' => 25, 'to' => 'acc-2']);

    expect($outcome->status)->toBe(ExecutionStatus::Confirmed)
        ->and($this->app()->make(Ledger::class)->lines)->toBe(['hook start transfer', 'hold 25', 'credit hold acc-2', 'commit hold-25', 'credit commit credit-acc-2']);
});

it('cancels the tried participant when another try fails', function (): void {
    /** @var OrchestrationCapstoneTestCase $this */
    $outcome = $this->app()->make(Orchestrator::class)->start(ExecutionPattern::Tcc, 'transfer', ['amount' => 25, 'to' => 'closed']);

    expect($outcome->status)->toBe(ExecutionStatus::Canceled)
        ->and($this->app()->make(Ledger::class)->lines)->toBe(['hook start transfer', 'hold 25', 'release hold-25']);
});
