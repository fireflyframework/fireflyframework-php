<?php

declare(strict_types=1);

use Firefly\EventSourcing\Store\EventSourcingSchema;
use Firefly\EventSourcing\Tests\Support\EventSourcedOrchestrationIntegrationTestCase;
use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Core\Orchestrator;
use Firefly\Orchestration\Persistence\OrchestrationSchema;
use Firefly\Orchestration\Tests\Support\IntegrationDatabase;
use Firefly\Orchestration\Workflow\Engine\WorkflowEngine;
use Illuminate\Support\Facades\DB;

uses(EventSourcedOrchestrationIntegrationTestCase::class);

it('runs a saga and parks a workflow through the event-sourced store on the server', function (string $lane): void {
    /** @var EventSourcedOrchestrationIntegrationTestCase $this */
    $outcome = $this->app()->make(Orchestrator::class)->start(ExecutionPattern::Saga, 'checkout', ['orderId' => 7, 'amount' => 40]);
    $workflows = $this->app()->make(WorkflowEngine::class);
    $workflows->start('onboarding', ['email' => 'ada@example.com'], correlationId: 'onb-es-it');

    expect(DB::connection()->getDriverName())->toBe(IntegrationDatabase::driver($lane))
        ->and($outcome->success)->toBeTrue()
        ->and($workflows->find('onb-es-it')?->status)->toBe(ExecutionStatus::Waiting)
        ->and(DB::table(EventSourcingSchema::EVENTS)->where('aggregate_type', 'orchestration.execution')->count())->toBeGreaterThan(4)
        ->and(DB::table(OrchestrationSchema::EXECUTIONS)->where('correlation_id', $outcome->correlationId)->value('status'))->toBe('COMPLETED')
        ->and(array_map(static fn ($state): string => $state->correlationId, $workflows->search(['email' => 'ada@example.com'])))->toBe(['onb-es-it']);
})->with(IntegrationDatabase::lanes())->group('integration');
