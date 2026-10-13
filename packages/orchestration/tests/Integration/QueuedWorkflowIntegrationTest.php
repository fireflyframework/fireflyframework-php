<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Tests\CapstoneFixtures\Ledger;
use Firefly\Orchestration\Tests\Support\IntegrationDatabase;
use Firefly\Orchestration\Tests\Support\QueuedWorkflowCapstoneTestCase;
use Firefly\Orchestration\Tests\Support\QueuedWorkflowIntegrationTestCase;
use Firefly\Orchestration\Workflow\Engine\WorkflowEngine;
use Firefly\Orchestration\Workflow\Queue\RunWorkflowJob;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;

uses(QueuedWorkflowIntegrationTestCase::class);

it('queues an ASYNC workflow on the database queue, runs it in a worker and ignores a redelivery', function (string $lane): void {
    /** @var QueuedWorkflowIntegrationTestCase $this */
    $workflows = $this->app()->make(WorkflowEngine::class);
    $ledger = $this->app()->make(Ledger::class);

    $started = $workflows->start('statement', ['account' => 'acc-ü'], correlationId: 'stmt-it');
    $queued = DB::table(QueuedWorkflowCapstoneTestCase::JOBS_TABLE)->pluck('queue')->all();

    expect(DB::connection()->getDriverName())->toBe(IntegrationDatabase::driver($lane))
        ->and($started->status)->toBe(ExecutionStatus::Pending)
        ->and($queued)->toBe(['workflows']);

    $exit = $this->work();
    $finished = $workflows->find('stmt-it');

    expect($exit)->toBe(0)
        ->and($finished?->status)->toBe(ExecutionStatus::Completed)
        ->and($finished?->stepResults)->toBe(['render' => 'statement-acc-ü', 'deliver' => 'delivered'])
        ->and(DB::table(QueuedWorkflowCapstoneTestCase::JOBS_TABLE)->count())->toBe(0);

    $this->app()->make(Dispatcher::class)->dispatch((new RunWorkflowJob('stmt-it'))->onConnection('database')->onQueue('workflows'));

    expect($this->work())->toBe(0)
        ->and(DB::table(QueuedWorkflowCapstoneTestCase::JOBS_TABLE)->count())->toBe(0)
        ->and($workflows->find('stmt-it')?->toArray())->toBe($finished?->toArray())
        ->and($ledger->lines)->toBe(['hook start statement', 'render acc-ü', 'deliver acc-ü']);
})->with(IntegrationDatabase::lanes())->group('integration');
