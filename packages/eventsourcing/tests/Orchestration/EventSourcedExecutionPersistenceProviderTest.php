<?php

declare(strict_types=1);

use Firefly\EventSourcing\Exception\EventSourcingException;
use Firefly\EventSourcing\Orchestration\ExecutionReadModelProjection;
use Firefly\EventSourcing\Projection\DatabaseCheckpointStore;
use Firefly\EventSourcing\Projection\ProjectionRegistry;
use Firefly\EventSourcing\Projection\ProjectionRunner;
use Firefly\EventSourcing\Projection\ProjectionRunResult;
use Firefly\EventSourcing\Store\EventSourcingSchema;
use Firefly\EventSourcing\Tests\Support\EventSourcedOrchestration;
use Firefly\EventSourcing\Tests\Support\EventSourcingDatabase;
use Firefly\Orchestration\Persistence\ExecutionCriteria;
use Firefly\Orchestration\Persistence\OrchestrationSchema;
use Firefly\Orchestration\Saga\Builder\SagaBuilder;
use Firefly\Orchestration\Tests\Support\ExecutionPersistenceContract;
use Firefly\Orchestration\Tests\Support\TestEngines;

foreach (ExecutionPersistenceContract::cases() as $case => $assert) {
    it("event-sourced persistence on the memory store {$case}", function () use ($assert): void {
        $assert(EventSourcedOrchestration::provider());
    });

    it("event-sourced persistence on SQLite {$case}", function () use ($assert): void {
        $assert(EventSourcedOrchestration::provider(EventSourcedOrchestration::fresh(EventSourcingDatabase::sqlite())));
    });
}

it('stores a saga\'s checkpoints as events and snapshots, and replays it exactly as the read model holds it', function (): void {
    $connection = EventSourcedOrchestration::fresh(EventSourcingDatabase::sqlite());
    $provider = EventSourcedOrchestration::provider($connection, snapshotThreshold: 3);
    $engines = TestEngines::create(persistence: $provider);
    $saga = SagaBuilder::saga('es')
        ->step('a')->handler(static fn (): array => ['n' => 1, 'amount' => 10.0])->add()
        ->step('b')->dependsOn('a')->handler(static fn (): string => 'b')->add()
        ->build();

    $result = $engines->sagas->execute($saga, ['orderId' => 7], correlationId: 'es-1');
    $types = $connection->table(EventSourcingSchema::EVENTS)->where('aggregate_id', 'es-1')->orderBy('aggregate_version')->pluck('event_type')->all();

    expect($result->isSuccess())->toBeTrue()
        ->and($types[0] ?? null)->toBe('orchestration.execution.started')
        ->and(count($types))->toBeGreaterThanOrEqual(3)
        ->and($connection->table(EventSourcingSchema::SNAPSHOTS)->where('aggregate_id', 'es-1')->count())->toBeGreaterThan(0)
        ->and($provider->findById('es-1')?->toArray())->toBe($provider->readModel()->findById('es-1')?->toArray())
        ->and($provider->findById('es-1')?->stepResults)->toBe(['a' => ['n' => 1, 'amount' => 10.0], 'b' => 'b'])
        ->and($connection->table(OrchestrationSchema::EXECUTIONS)->where('correlation_id', 'es-1')->value('status'))->toBe('COMPLETED');
});

it('rolls the events and the read model back together with the caller\'s transaction', function (): void {
    $connection = EventSourcedOrchestration::fresh(EventSourcingDatabase::sqlite());
    $provider = EventSourcedOrchestration::provider($connection);

    try {
        $connection->transaction(static function () use ($provider): void {
            $provider->save(ExecutionPersistenceContract::state('rb-1'));

            throw new RuntimeException('the step failed after its checkpoint');
        });
    } catch (RuntimeException) {
    }

    expect($provider->findById('rb-1'))->toBeNull()
        ->and($connection->table(EventSourcingSchema::EVENTS)->count())->toBe(0)
        ->and($connection->table(OrchestrationSchema::EXECUTIONS)->count())->toBe(0);
});

it('tombstones a deleted execution instead of purging its stream, and lets the id start again', function (): void {
    $connection = EventSourcedOrchestration::fresh(EventSourcingDatabase::sqlite());
    $provider = EventSourcedOrchestration::provider($connection);
    $provider->save(ExecutionPersistenceContract::state('d-1'));

    expect($provider->delete('d-1'))->toBeTrue()
        ->and($provider->findById('d-1'))->toBeNull()
        ->and($connection->table(EventSourcingSchema::EVENTS)->where('aggregate_id', 'd-1')->orderByDesc('aggregate_version')->value('event_type'))->toBe('orchestration.execution.deleted');

    $provider->save(ExecutionPersistenceContract::state('d-1'));

    expect($provider->findById('d-1')?->correlationId)->toBe('d-1')
        ->and(ExecutionPersistenceContract::ids($provider->find(new ExecutionCriteria)))->toBe(['d-1']);
});

it('rebuilds the read model from the events with its inline projection, and only through a rebuild', function (): void {
    $connection = EventSourcedOrchestration::fresh(EventSourcingDatabase::sqlite());
    $support = EventSourcedOrchestration::support($connection);
    $provider = EventSourcedOrchestration::from($support, $connection);
    $provider->save(ExecutionPersistenceContract::state('r-1', overrides: ['searchAttributes' => ['customer' => 'ada']]));
    $provider->save(ExecutionPersistenceContract::state('r-2'));
    $provider->delete('r-2');
    $connection->table(OrchestrationSchema::SEARCH_ATTRIBUTES)->delete();
    $connection->table(OrchestrationSchema::EXECUTIONS)->delete();
    $projection = new ExecutionReadModelProjection($support, $provider->readModel());
    $runner = new ProjectionRunner($support->store, new DatabaseCheckpointStore($connection), new ProjectionRegistry([$projection]));

    $result = $runner->rebuild(ExecutionReadModelProjection::NAME);

    expect($result->outcome)->toBe(ProjectionRunResult::CAUGHT_UP)
        ->and(ExecutionPersistenceContract::ids($provider->find(new ExecutionCriteria)))->toBe(['r-1'])
        ->and(ExecutionPersistenceContract::ids($provider->findBySearchAttributes(['customer' => 'ada'])))->toBe(['r-1'])
        ->and(fn () => $runner->catchUp(ExecutionReadModelProjection::NAME))->toThrow(EventSourcingException::class, 'is inline');
});
