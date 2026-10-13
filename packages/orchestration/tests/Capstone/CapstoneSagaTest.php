<?php

declare(strict_types=1);

use Firefly\Eda\EventEnvelope;
use Firefly\Eda\EventPublisher;
use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Core\Orchestrator;
use Firefly\Orchestration\Persistence\OrchestrationSchema;
use Firefly\Orchestration\Saga\Registry\SagaRegistry;
use Firefly\Orchestration\Tests\CapstoneFixtures\Ledger;
use Firefly\Orchestration\Tests\Support\OrchestrationCapstoneTestCase;
use Illuminate\Support\Facades\DB;

uses(OrchestrationCapstoneTestCase::class);

it('discovers the saga from its attributes and runs it through the real beans into the database', function (): void {
    /** @var OrchestrationCapstoneTestCase $this */
    $seen = [];
    $this->app()->make(EventPublisher::class)->subscribe('*', static function (EventEnvelope $envelope) use (&$seen): void {
        $seen[] = $envelope->eventType;
    });

    $outcome = $this->app()->make(Orchestrator::class)->start(ExecutionPattern::Saga, 'checkout', ['orderId' => 7, 'amount' => 40]);

    expect($this->app()->make(SagaRegistry::class)->names())->toBe(['checkout'])
        ->and($outcome->success)->toBeTrue()
        ->and($this->app()->make(Ledger::class)->lines)->toBe(['hook start checkout', 'reserve 7', 'charge 40 for r-7', 'completed checkout'])
        ->and(DB::table(OrchestrationSchema::EXECUTIONS)->where('correlation_id', $outcome->correlationId)->value('status'))->toBe('COMPLETED')
        ->and($seen)->toContain('orchestration.execution_started', 'payment.captured', 'orchestration.execution_completed');
});

it('compensates in reverse, calls #[OnSagaError] and dead-letters into the database', function (): void {
    /** @var OrchestrationCapstoneTestCase $this */
    $outcome = $this->app()->make(Orchestrator::class)->start(ExecutionPattern::Saga, 'checkout', ['orderId' => 8, 'amount' => 500]);

    expect($outcome->success)->toBeFalse()
        ->and($this->app()->make(Ledger::class)->lines)->toBe(['hook start checkout', 'reserve 8', 'release r-8', 'failed: amount 500 exceeds the limit'])
        ->and(DB::table(OrchestrationSchema::DEAD_LETTERS)->where('correlation_id', $outcome->correlationId)->value('step_id'))->toBe('charge')
        ->and(DB::table(OrchestrationSchema::EXECUTIONS)->where('correlation_id', $outcome->correlationId)->value('status'))->toBe('FAILED');
});

it('starts the saga from its trigger event on the EDA bus', function (): void {
    /** @var OrchestrationCapstoneTestCase $this */
    $this->app()->make(EventPublisher::class)->publish('orders', 'orders.placed', ['orderId' => 9, 'amount' => 10]);

    expect($this->app()->make(Ledger::class)->lines)->toContain('reserve 9', 'charge 10 for r-9');
});
