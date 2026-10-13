<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Core\Orchestrator;
use Firefly\Orchestration\Dlq\DeadLetterService;
use Firefly\Orchestration\Tests\CapstoneFixtures\Ledger;
use Firefly\Orchestration\Tests\Support\OrchestrationCapstoneTestCase;
use Firefly\Orchestration\Workflow\Engine\WorkflowEngine;

uses(OrchestrationCapstoneTestCase::class);

it('lists and shows executions', function (): void {
    /** @var OrchestrationCapstoneTestCase $this */
    $outcome = $this->app()->make(Orchestrator::class)->start(ExecutionPattern::Saga, 'checkout', ['orderId' => 3, 'amount' => 10]);

    expect($this->command('firefly:orchestration:list', ['--pattern' => 'saga']))->toBe(0)
        ->and($this->commandOutput())->toContain($outcome->correlationId)->toContain('COMPLETED')
        ->and($this->command('firefly:orchestration:show', ['id' => $outcome->correlationId]))->toBe(0)
        ->and($this->commandOutput())->toContain('"executionName": "checkout"')
        ->and($this->command('firefly:orchestration:show', ['id' => 'nope']))->toBe(1)
        ->and($this->commandOutput())->toContain('No orchestration execution [nope] is persisted.')
        ->and($this->command('firefly:orchestration:list', ['--pattern' => 'nonsense']))->toBe(1)
        ->and($this->commandOutput())->toContain('Unknown pattern [nonsense]');
});

it('signals a waiting workflow and ticks its timer', function (): void {
    /** @var OrchestrationCapstoneTestCase $this */
    $this->app()->make(WorkflowEngine::class)->start('onboarding', ['email' => 'grace@example.com'], correlationId: 'cmd-1');

    expect($this->command('firefly:orchestration:signal', ['id' => 'cmd-1', 'signal' => 'email-verified', '--payload' => '{"token":"t"}']))->toBe(0)
        ->and($this->commandOutput())->toContain('WAITING');
    usleep(5_000);
    expect($this->command('firefly:orchestration:tick'))->toBe(0)
        ->and($this->commandOutput())->toContain('Advanced 1 waiting workflow');

    expect($this->app()->make(WorkflowEngine::class)->find('cmd-1')?->status)->toBe(ExecutionStatus::Completed)
        ->and($this->command('firefly:orchestration:signal', ['id' => 'cmd-1', 'signal' => 'x', '--payload' => '{']))->toBe(1)
        ->and($this->commandOutput())->toContain('--payload is not JSON');
});

it('lists dead letters and retries one', function (): void {
    /** @var OrchestrationCapstoneTestCase $this */
    $this->app()->make(Orchestrator::class)->start(ExecutionPattern::Saga, 'checkout', ['orderId' => 4, 'amount' => 500]);
    $entry = $this->app()->make(DeadLetterService::class)->all()[0];

    expect($this->command('firefly:orchestration:dlq'))->toBe(0)
        ->and($this->commandOutput())->toContain($entry->id)->toContain('amount 500 exceeds the limit')
        ->and($this->command('firefly:orchestration:dlq', ['--name' => 'onboarding']))->toBe(0)
        ->and($this->commandOutput())->not->toContain($entry->id)
        ->and($this->command('firefly:orchestration:dlq:retry', ['id' => $entry->id]))->toBe(1)
        ->and($this->commandOutput())->toContain('FAILED');

    expect($this->app()->make(DeadLetterService::class)->all()[0]->retryCount)->toBe(1)
        ->and($this->app()->make(Ledger::class)->lines)->toContain('reserve 4');
});
