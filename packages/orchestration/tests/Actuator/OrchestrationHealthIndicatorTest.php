<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Core\Orchestrator;
use Firefly\Orchestration\Persistence\OrchestrationSchema;
use Firefly\Orchestration\Tests\Support\ActuatorCapstoneTestCase;
use Firefly\Orchestration\Workflow\Engine\WorkflowEngine;
use Illuminate\Support\Facades\Schema;

uses(ActuatorCapstoneTestCase::class);

it('reports an orchestration health component with the store, open executions and dead letters', function (): void {
    /** @var ActuatorCapstoneTestCase $this */
    $health = $this->getJson('/actuator/health');

    expect($health->json('components.orchestration.status'))->toBe('UP')
        ->and($health->json('components.orchestration.details'))->toBe(['provider' => 'database', 'inFlight' => 0, 'stale' => 0, 'deadLetters' => 0]);
});

it('reports the component DOWN when the execution store does not answer', function (): void {
    /** @var ActuatorCapstoneTestCase $this */
    Schema::drop(OrchestrationSchema::EXECUTIONS);

    $health = $this->getJson('/actuator/health');

    expect($health->json('components.orchestration.status'))->toBe('DOWN')
        ->and($health->json('components.orchestration.details'))->toBe(['provider' => 'database', 'error' => 'the execution store does not answer']);
});

it('counts open executions and dead letters in the component details', function (): void {
    /** @var ActuatorCapstoneTestCase $this */
    $this->app()->make(WorkflowEngine::class)->start('onboarding', ['email' => 'ada@example.com'], correlationId: 'health-1');
    $this->app()->make(Orchestrator::class)->start(ExecutionPattern::Saga, 'checkout', ['orderId' => 5, 'amount' => 500]);

    expect($this->getJson('/actuator/health')->json('components.orchestration.details'))
        ->toBe(['provider' => 'database', 'inFlight' => 1, 'stale' => 0, 'deadLetters' => 1]);
});
