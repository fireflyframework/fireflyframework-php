<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Core\StepStatus;
use Firefly\Orchestration\Persistence\OrchestrationSchema;
use Firefly\Orchestration\Tests\CapstoneFixtures\Ledger;
use Firefly\Orchestration\Tests\Support\IntegrationDatabase;
use Firefly\Orchestration\Tests\Support\OrchestrationIntegrationTestCase;
use Firefly\Orchestration\Workflow\Engine\WorkflowEngine;
use Illuminate\Support\Facades\DB;

uses(OrchestrationIntegrationTestCase::class);

it('runs a #[CompensationStep] method with the step result when a discovered workflow fails, and stores it on the server', function (string $lane): void {
    /** @var OrchestrationIntegrationTestCase $this */
    $workflows = $this->app()->make(WorkflowEngine::class);

    $failed = $workflows->start('shipment', ['parcel' => 'pä-7', 'carrier' => 'closed'], correlationId: 'ship-it');
    $stored = $workflows->find('ship-it');

    expect(DB::connection()->getDriverName())->toBe(IntegrationDatabase::driver($lane))
        ->and($failed->status)->toBe(ExecutionStatus::Failed)
        ->and($this->app()->make(Ledger::class)->lines)->toBe(['hook start shipment', 'book pä-7', 'void label-pä-7'])
        ->and($stored?->stepStatus('book'))->toBe(StepStatus::Compensated)
        ->and($stored?->stepResults['book'] ?? null)->toBe('label-pä-7')
        ->and($stored?->compensationResults)->toBe(['book' => 'voided label-pä-7'])
        ->and(DB::table(OrchestrationSchema::EXECUTIONS)->where('correlation_id', 'ship-it')->value('status'))->toBe('FAILED')
        ->and(DB::table(OrchestrationSchema::DEAD_LETTERS)->where('correlation_id', 'ship-it')->value('step_id'))->toBe('dispatch');
})->with(IntegrationDatabase::lanes())->group('integration');
