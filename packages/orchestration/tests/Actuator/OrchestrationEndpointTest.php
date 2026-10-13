<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Core\Orchestrator;
use Firefly\Orchestration\Tests\Support\ActuatorCapstoneTestCase;

uses(ActuatorCapstoneTestCase::class);

it('describes every definition, the execution counts and the DLQ', function (): void {
    /** @var ActuatorCapstoneTestCase $this */
    $this->app()->make(Orchestrator::class)->start(ExecutionPattern::Saga, 'checkout', ['orderId' => 1, 'amount' => 500]);

    $overview = $this->getJson('/actuator/orchestration')->assertOk();

    expect($overview->json('persistence'))->toBe('database')
        ->and($overview->json('sagas.checkout.layers'))->toBe([['reserve'], ['charge']])
        ->and($overview->json('sagas.checkout.triggerEventType'))->toBe('orders.placed')
        ->and($overview->json('tccs.transfer.participants'))->toBe(['debit', 'credit'])
        ->and($overview->json('workflows.onboarding.queries'))->toBe(['progress'])
        ->and($overview->json('executions.FAILED'))->toBe(1)
        ->and($overview->json('deadLetters'))->toBe(1)
        ->and($overview->json('triggers.0.eventType'))->toBe('orders.placed')
        ->and($overview->json('notes.0'))->toBeString()->toContain('run one after another');
});

it('lists and shows executions and dead letters', function (): void {
    /** @var ActuatorCapstoneTestCase $this */
    $outcome = $this->app()->make(Orchestrator::class)->start(ExecutionPattern::Saga, 'checkout', ['orderId' => 2, 'amount' => 10]);

    $list = $this->getJson('/actuator/orchestration/executions?pattern=SAGA&status=COMPLETED')->assertOk();
    $show = $this->getJson('/actuator/orchestration/executions/'.$outcome->correlationId)->assertOk();

    expect($list->json('executions.0.correlationId'))->toBe($outcome->correlationId)
        ->and($show->json('status'))->toBe('COMPLETED')
        ->and($show->json('report.steps.charge.status'))->toBe('DONE');

    $this->getJson('/actuator/orchestration/executions/nope')->assertNotFound();
    $this->getJson('/actuator/orchestration/dlq')->assertOk()->assertJson(['deadLetters' => []]);
});
