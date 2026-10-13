<?php

declare(strict_types=1);

use Firefly\Domain\DomainEvent;
use Firefly\EventSourcing\Aggregate\EventSourcingSupport;
use Firefly\EventSourcing\Event\EventMapper;
use Firefly\EventSourcing\Event\EventTypeRegistry;
use Firefly\EventSourcing\Orchestration\Event\ExecutionCheckpointed;
use Firefly\EventSourcing\Orchestration\Event\ExecutionDeleted;
use Firefly\EventSourcing\Orchestration\Event\ExecutionStarted;
use Firefly\EventSourcing\Orchestration\Event\ExecutionStatusChanged;
use Firefly\EventSourcing\Orchestration\Event\SearchAttributesChanged;
use Firefly\EventSourcing\Orchestration\Event\StepResultRecorded;
use Firefly\EventSourcing\Orchestration\Event\StepStatusChanged;
use Firefly\EventSourcing\Orchestration\ExecutionAggregate;
use Firefly\EventSourcing\Orchestration\OrchestrationEventTypes;
use Firefly\EventSourcing\Snapshot\MemorySnapshotStore;
use Firefly\EventSourcing\Snapshot\SnapshotPolicy;
use Firefly\EventSourcing\Store\MemoryEventStore;
use Firefly\Orchestration\Persistence\ExecutionState;
use Firefly\Orchestration\Tests\Support\ExecutionPersistenceContract;

/** @param array<string, mixed> $changes */
function executionAggregateNext(ExecutionState $state, array $changes): ExecutionState
{
    return ExecutionState::fromArray(array_replace($state->toArray(), $changes));
}

/** @return list<class-string> */
function executionAggregateEvents(ExecutionAggregate $aggregate): array
{
    return array_map(static fn (DomainEvent $event): string => $event::class, $aggregate->uncommittedEvents());
}

it('starts with the whole state, then records one semantic event per kind of change', function (): void {
    $aggregate = new ExecutionAggregate('c-1');
    $started = ExecutionPersistenceContract::state('c-1');
    $next = executionAggregateNext($started, [
        'updatedAt' => '2026-10-12T10:01:00.000000+00:00',
        'stepStatuses' => ['reserve' => 'DONE', 'charge' => 'DONE'],
        'stepResults' => ['reserve' => ['hold' => 'h-c-1'], 'charge' => ['amount' => 10.0]],
        'searchAttributes' => ['customer' => 'ada'],
        'variables' => ['attempt' => 2],
    ]);

    $aggregate->checkpoint($started);
    $aggregate->checkpoint($next);
    $aggregate->checkpoint($next);

    expect(executionAggregateEvents($aggregate))->toBe([
        ExecutionStarted::class,
        ExecutionStatusChanged::class,
        StepStatusChanged::class,
        StepResultRecorded::class,
        SearchAttributesChanged::class,
        ExecutionCheckpointed::class,
    ])
        ->and($aggregate->state()?->toArray())->toBe($next->toArray())
        ->and(OrchestrationEventTypes::types())->toHaveCount(7);
});

it('falls back to one checkpointed event for a change no semantic event reproduces exactly', function (): void {
    $aggregate = new ExecutionAggregate('c-2');
    $first = ExecutionPersistenceContract::state('c-2', overrides: ['stepStatuses' => ['a' => 'DONE', 'b' => 'DONE']]);
    $reordered = executionAggregateNext($first, ['stepStatuses' => ['b' => 'DONE', 'a' => 'DONE']]);

    $aggregate->checkpoint($first);
    $aggregate->checkpoint($reordered);

    expect(executionAggregateEvents($aggregate))->toBe([ExecutionStarted::class, ExecutionCheckpointed::class])
        ->and($aggregate->state()?->toArray())->toBe($reordered->toArray());
});

it('replays to exactly the state it was given, whole floats included, from events and from a snapshot', function (): void {
    $aggregate = new ExecutionAggregate('c-3');
    $first = ExecutionPersistenceContract::state('c-3');
    $last = executionAggregateNext($first, ['status' => 'COMPLETED', 'completedAt' => '2026-10-12T10:05:00.000000+00:00', 'stepResults' => ['reserve' => ['hold' => 'h'], 'charge' => 10.0]]);
    $aggregate->checkpoint($first);
    $aggregate->checkpoint($last);

    $replayed = ExecutionAggregate::reconstitute('c-3', $aggregate->uncommittedEvents());
    $restored = ExecutionAggregate::reconstitute('c-3', [], $aggregate->version(), $aggregate->snapshotState());

    expect($replayed->state()?->toArray())->toBe($last->toArray())
        ->and($restored->state()?->toArray())->toBe($last->toArray())
        ->and($replayed->state()?->stepResults['charge'] ?? null)->toBe(10.0);
});

it('round-trips through a store and a snapshot with the registered event types', function (): void {
    $support = new EventSourcingSupport(new MemoryEventStore, new EventMapper(new EventTypeRegistry), new MemorySnapshotStore, new SnapshotPolicy(true, 2, 2));
    $executions = OrchestrationEventTypes::repository($support);
    OrchestrationEventTypes::register($support->mapper->types());
    $state = ExecutionPersistenceContract::state('c-4');

    $aggregate = new ExecutionAggregate('c-4');
    $aggregate->checkpoint($state);
    $executions->save($aggregate);
    $next = executionAggregateNext($state, ['status' => 'WAITING', 'wakeAt' => '2026-10-12T11:00:00.000000+00:00', 'timers' => ['t' => '2026-10-12T11:00:00.000000+00:00']]);
    $reloaded = $executions->get('c-4');
    $reloaded->checkpoint($next);
    $executions->save($reloaded);

    expect($executions->get('c-4')->state()?->toArray())->toBe($next->toArray())
        ->and($support->snapshots->latest('c-4')?->version)->toBe(3)
        ->and($support->store->load('c-4')->events[0]->eventType)->toBe('orchestration.execution.started');
});

it('tombstones a deleted execution once, and starts it again on the next checkpoint', function (): void {
    $aggregate = new ExecutionAggregate('c-5');
    $state = ExecutionPersistenceContract::state('c-5');
    $aggregate->checkpoint($state);
    $aggregate->delete();
    $aggregate->delete();

    expect([$aggregate->state(), $aggregate->isDeleted()])->toBe([null, true])
        ->and(executionAggregateEvents($aggregate))->toBe([ExecutionStarted::class, ExecutionDeleted::class]);

    $aggregate->checkpoint($state);

    expect($aggregate->isDeleted())->toBeFalse()
        ->and($aggregate->state()?->toArray())->toBe($state->toArray())
        ->and(executionAggregateEvents($aggregate))->toBe([ExecutionStarted::class, ExecutionDeleted::class, ExecutionStarted::class]);
});
