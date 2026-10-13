<?php

declare(strict_types=1);

use Firefly\EventSourcing\Orchestration\EventSourcedExecutionPersistenceProvider;
use Firefly\EventSourcing\Orchestration\ExecutionReadModelProjection;
use Firefly\EventSourcing\Store\EventStore;
use Firefly\EventSourcing\Store\MemoryEventStore;
use Firefly\EventSourcing\Tests\Support\MemoryEventSourcedOrchestrationCapstoneTestCase;
use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Core\Orchestrator;
use Firefly\Orchestration\Persistence\ExecutionPersistenceProvider;
use Firefly\Orchestration\Persistence\MemoryExecutionPersistenceProvider;

uses(MemoryEventSourcedOrchestrationCapstoneTestCase::class);

it('runs the checkout saga on the memory event store with an in-memory read model', function (): void {
    /** @var MemoryEventSourcedOrchestrationCapstoneTestCase $this */
    $outcome = $this->app()->make(Orchestrator::class)->start(ExecutionPattern::Saga, 'checkout', ['orderId' => 9, 'amount' => 10]);
    $persistence = $this->app()->make(ExecutionPersistenceProvider::class);
    // The read model the provider queries is the read-model projection bean's, the one the rebuild command replays into.
    $readModel = $this->app()->make(ExecutionReadModelProjection::class)->readModel();

    expect($this->app()->make(EventStore::class))->toBeInstanceOf(MemoryEventStore::class)
        ->and($persistence)->toBeInstanceOf(EventSourcedExecutionPersistenceProvider::class)
        ->and($readModel)->toBeInstanceOf(MemoryExecutionPersistenceProvider::class)
        ->and($outcome->success)->toBeTrue()
        ->and($persistence->findById($outcome->correlationId)?->status)->toBe(ExecutionStatus::Completed)
        ->and($persistence->countByStatus())->toBe(['COMPLETED' => 1]);
});
