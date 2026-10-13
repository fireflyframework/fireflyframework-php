<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Tests\CapstoneFixtures\Ledger;
use Firefly\Orchestration\Tests\Support\InlineQueuedWorkflowCapstoneTestCase;
use Firefly\Orchestration\Tests\Support\QueuedWorkflowCapstoneTestCase;
use Firefly\Orchestration\Workflow\Engine\WorkflowEngine;
use Illuminate\Support\Facades\DB;

uses(InlineQueuedWorkflowCapstoneTestCase::class);

it('runs an ASYNC workflow inside the start when the configured queue connection is sync', function (): void {
    /** @var InlineQueuedWorkflowCapstoneTestCase $this */
    $workflows = $this->app()->make(WorkflowEngine::class);

    $started = $workflows->start('statement', ['account' => 'acc-3'], correlationId: 'stmt-sync');

    expect($started->status)->toBe(ExecutionStatus::Completed)
        ->and($workflows->find('stmt-sync')?->status)->toBe(ExecutionStatus::Completed)
        ->and(DB::table(QueuedWorkflowCapstoneTestCase::JOBS_TABLE)->count())->toBe(0)
        ->and($this->app()->make(Ledger::class)->lines)->toBe(['hook start statement', 'render acc-3', 'deliver acc-3']);
});
