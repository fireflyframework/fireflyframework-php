<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Tests\CapstoneFixtures\Ledger;
use Firefly\Orchestration\Tests\Support\QueuedWorkflowCapstoneTestCase;
use Firefly\Orchestration\Workflow\Engine\WorkflowEngine;
use Firefly\Orchestration\Workflow\Queue\BusWorkflowDispatcher;
use Firefly\Orchestration\Workflow\Queue\RunWorkflowJob;
use Firefly\Orchestration\Workflow\Queue\WorkflowDispatcher;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(QueuedWorkflowCapstoneTestCase::class);

it('wires the bus dispatcher and pushes a RunWorkflowJob on the configured connection and queue', function (): void {
    /** @var QueuedWorkflowCapstoneTestCase $this */
    Queue::fake();
    $workflows = $this->app()->make(WorkflowEngine::class);

    $started = $workflows->start('statement', ['account' => 'acc-1'], correlationId: 'stmt-fake');

    expect($this->app()->make(WorkflowDispatcher::class))->toBeInstanceOf(BusWorkflowDispatcher::class)
        ->and($started->status)->toBe(ExecutionStatus::Pending)
        ->and($workflows->find('stmt-fake')?->status)->toBe(ExecutionStatus::Pending)
        ->and($this->app()->make(Ledger::class)->lines)->toBe([]);
    Queue::assertPushedOn('workflows', RunWorkflowJob::class, static fn (RunWorkflowJob $job): bool => $job->correlationId === 'stmt-fake' && $job->connection === 'database');
    Queue::assertCount(1);
});

it('runs a PENDING workflow in a queue worker to COMPLETED and treats a redelivered job as a no-op', function (): void {
    /** @var QueuedWorkflowCapstoneTestCase $this */
    $workflows = $this->app()->make(WorkflowEngine::class);
    $ledger = $this->app()->make(Ledger::class);

    $started = $workflows->start('statement', ['account' => 'acc-2'], correlationId: 'stmt-1');
    $queued = DB::table(QueuedWorkflowCapstoneTestCase::JOBS_TABLE)->get(['queue', 'payload'])->map(static fn (object $row): array => (array) $row)->all();
    $payload = json_decode(is_string($queued[0]['payload'] ?? null) ? $queued[0]['payload'] : '', true);

    expect($started->status)->toBe(ExecutionStatus::Pending)
        ->and($ledger->lines)->toBe([])
        ->and($queued)->toHaveCount(1)
        ->and($queued[0]['queue'] ?? null)->toBe('workflows')
        ->and(data_get($payload, 'displayName'))->toBe(RunWorkflowJob::class);

    $exit = $this->work();
    $finished = $workflows->find('stmt-1');

    expect($exit)->toBe(0)
        ->and($finished?->status)->toBe(ExecutionStatus::Completed)
        ->and($finished?->stepResults)->toBe(['render' => 'statement-acc-2', 'deliver' => 'delivered'])
        ->and($ledger->lines)->toBe(['hook start statement', 'render acc-2', 'deliver acc-2'])
        ->and(DB::table(QueuedWorkflowCapstoneTestCase::JOBS_TABLE)->count())->toBe(0);

    // A redelivered job (a worker that died after the run but before the ack) finds a COMPLETED execution.
    $this->app()->make(Dispatcher::class)->dispatch((new RunWorkflowJob('stmt-1'))->onConnection('database')->onQueue('workflows'));
    $redelivered = $this->work();

    expect($redelivered)->toBe(0)
        ->and(DB::table(QueuedWorkflowCapstoneTestCase::JOBS_TABLE)->count())->toBe(0)
        ->and($workflows->find('stmt-1')?->toArray())->toBe($finished?->toArray())
        ->and($ledger->lines)->toBe(['hook start statement', 'render acc-2', 'deliver acc-2']);
});
