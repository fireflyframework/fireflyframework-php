<?php

declare(strict_types=1);

use Firefly\EventSourcing\EventSourcingWiringProvider;
use Firefly\EventSourcing\Orchestration\EventSourcedExecutionPersistenceProvider;
use Firefly\EventSourcing\Projection\ProjectionRegistry;
use Firefly\EventSourcing\Store\EventSourcingSchema;
use Firefly\EventSourcing\Store\EventStore;
use Firefly\EventSourcing\Tests\Support\EventSourcedOrchestrationCapstoneTestCase;
use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Core\Orchestrator;
use Firefly\Orchestration\Persistence\ExecutionPersistenceProvider;
use Firefly\Orchestration\Persistence\OrchestrationSchema;
use Firefly\Orchestration\Tests\CapstoneFixtures\Ledger;
use Firefly\Orchestration\Workflow\Engine\WorkflowEngine;
use Illuminate\Support\Facades\DB;

uses(EventSourcedOrchestrationCapstoneTestCase::class);

it('runs the checkout saga through the event-sourced execution store', function (): void {
    /** @var EventSourcedOrchestrationCapstoneTestCase $this */
    $outcome = $this->app()->make(Orchestrator::class)->start(ExecutionPattern::Saga, 'checkout', ['orderId' => 7, 'amount' => 40]);
    $stream = $this->app()->make(EventStore::class)->load($outcome->correlationId);

    expect($this->app()->make(ExecutionPersistenceProvider::class))->toBeInstanceOf(EventSourcedExecutionPersistenceProvider::class)
        ->and($outcome->success)->toBeTrue()
        ->and($this->app()->make(Ledger::class)->lines)->toBe(['hook start checkout', 'reserve 7', 'charge 40 for r-7', 'completed checkout'])
        ->and($stream->aggregateType())->toBe('orchestration.execution')
        ->and($stream->events[0]->eventType)->toBe('orchestration.execution.started')
        ->and($this->app()->make(Orchestrator::class)->find($outcome->correlationId)?->status)->toBe(ExecutionStatus::Completed)
        ->and(DB::table(OrchestrationSchema::EXECUTIONS)->where('correlation_id', $outcome->correlationId)->value('status'))->toBe('COMPLETED');
});

it('compensates and dead-letters a failing saga, its checkpoints still in the event store', function (): void {
    /** @var EventSourcedOrchestrationCapstoneTestCase $this */
    $outcome = $this->app()->make(Orchestrator::class)->start(ExecutionPattern::Saga, 'checkout', ['orderId' => 8, 'amount' => 500]);

    expect($outcome->success)->toBeFalse()
        ->and($this->app()->make(Ledger::class)->lines)->toBe(['hook start checkout', 'reserve 8', 'release r-8', 'failed: amount 500 exceeds the limit'])
        ->and(DB::table(OrchestrationSchema::DEAD_LETTERS)->where('correlation_id', $outcome->correlationId)->value('step_id'))->toBe('charge')
        ->and($this->app()->make(Orchestrator::class)->find($outcome->correlationId)?->status)->toBe(ExecutionStatus::Failed)
        ->and(DB::table(EventSourcingSchema::EVENTS)->where('aggregate_id', $outcome->correlationId)->count())->toBeGreaterThan(2);
});

it('parks a workflow on a signal and a timer and finishes it, searching the read model', function (): void {
    /** @var EventSourcedOrchestrationCapstoneTestCase $this */
    $workflows = $this->app()->make(WorkflowEngine::class);

    $started = $workflows->start('onboarding', ['email' => 'ada@example.com'], correlationId: 'onb-es');
    $workflows->signal('onb-es', 'email-verified', ['token' => 't-1']);
    usleep(5_000);
    $workflows->resumeDue();

    expect($started->status)->toBe(ExecutionStatus::Waiting)
        ->and($workflows->find('onb-es')?->status)->toBe(ExecutionStatus::Completed)
        ->and($this->app()->make(Ledger::class)->lines)->toBe(['hook start onboarding', 'register ada@example.com', 'verified', 'cooled off'])
        ->and(array_map(static fn ($state): string => $state->correlationId, $workflows->search(['email' => 'ada@example.com'])))->toBe(['onb-es']);
});

it('rebuilds the execution read model from the event store with the projections command', function (): void {
    /** @var EventSourcedOrchestrationCapstoneTestCase $this */
    $this->app()->make(WorkflowEngine::class)->start('onboarding', ['email' => 'grace@example.com'], correlationId: 'onb-rebuild');
    DB::table(OrchestrationSchema::SEARCH_ATTRIBUTES)->delete();
    DB::table(OrchestrationSchema::EXECUTIONS)->delete();

    $exit = $this->command('firefly:eventsourcing:projections:rebuild', ['projection' => 'orchestration.executions']);
    $rebuilt = $this->commandOutput();
    $this->command('firefly:eventsourcing:projections');

    expect($exit)->toBe(0)
        ->and($rebuilt)->toContain('orchestration.executions rebuilt')
        ->and(DB::table(OrchestrationSchema::EXECUTIONS)->where('correlation_id', 'onb-rebuild')->value('status'))->toBe('WAITING')
        ->and(array_map(static fn ($state): string => $state->correlationId, $this->app()->make(WorkflowEngine::class)->search(['email' => 'grace@example.com'])))->toBe(['onb-rebuild'])
        ->and($this->commandOutput())->toMatch('/orchestration\.executions\s*\|\s*inline/')
        ->and($this->app()->make(ProjectionRegistry::class)->has('orchestration.executions'))->toBeTrue();
});

it('loads the eventsourcing tables and the read-model migration on the store connection', function (): void {
    /** @var EventSourcedOrchestrationCapstoneTestCase $this */
    $migrator = $this->app()->make('migrator');

    expect($migrator->paths())->toContain(
        EventSourcingWiringProvider::MIGRATIONS.'/'.EventSourcingWiringProvider::TABLES_MIGRATION,
        EventSourcingWiringProvider::MIGRATIONS.'/'.EventSourcingWiringProvider::ORCHESTRATION_MIGRATION,
    );
});
