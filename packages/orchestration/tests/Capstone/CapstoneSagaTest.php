<?php

declare(strict_types=1);

use Firefly\Eda\Bus\SubscriberRegistry;
use Firefly\Eda\EventEnvelope;
use Firefly\Eda\EventPublisher;
use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Core\Orchestrator;
use Firefly\Orchestration\Event\EventGateway;
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

it('acknowledges a redelivered trigger event as a no-op instead of running the saga twice', function (): void {
    /** @var OrchestrationCapstoneTestCase $this */
    $delivered = [];
    $this->app()->make(EventPublisher::class)->subscribe('orders.placed', static function (EventEnvelope $envelope) use (&$delivered): void {
        $delivered[] = $envelope;
    });
    $this->app()->make(EventPublisher::class)->publish('orders', 'orders.placed', ['orderId' => 11, 'amount' => 20], ['tenant' => 'acme']);
    expect($delivered)->toHaveCount(1);
    $envelope = $delivered[0];

    // The broker hands the same envelope over again (a consumer crashed before acking it).
    $this->app()->make(SubscriberRegistry::class)->deliver($envelope);

    $correlationId = EventGateway::correlationIdFor('orders.placed', $envelope->eventId);
    $state = $this->app()->make(Orchestrator::class)->find($correlationId);

    expect(array_values(array_filter($this->app()->make(Ledger::class)->lines, static fn (string $line): bool => str_starts_with($line, 'reserve') || str_starts_with($line, 'charge'))))
        ->toBe(['reserve 11', 'charge 20 for r-11'])
        ->and(DB::table(OrchestrationSchema::EXECUTIONS)->where('execution_name', 'checkout')->count())->toBe(1)
        ->and($state?->status->value)->toBe('COMPLETED')
        ->and($state?->headers)->toMatchArray(['tenant' => 'acme', 'eventId' => $envelope->eventId, 'eventType' => 'orders.placed']);
});
