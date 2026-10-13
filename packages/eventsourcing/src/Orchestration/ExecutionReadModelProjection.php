<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Orchestration;

use Firefly\EventSourcing\Aggregate\EventSourcedRepository;
use Firefly\EventSourcing\Aggregate\EventSourcingSupport;
use Firefly\EventSourcing\Event\StoredEvent;
use Firefly\EventSourcing\Projection\InlineProjection;
use Firefly\Orchestration\Persistence\ExecutionCriteria;
use Firefly\Orchestration\Persistence\ExecutionPersistenceProvider;

/**
 * The event-sourced orchestration read model, as a projection: EventSourcedExecutionPersistenceProvider keeps it
 * current inline, in each save's transaction, and `php artisan firefly:eventsourcing:projections:rebuild
 * orchestration.executions` rebuilds it from the event store (after a restore, or to build it the first time).
 *
 * handle() writes the execution's CURRENT state, replayed from its stream, rather than folding the one event, so
 * replaying any number of an execution's events in any batch leaves the same row: the rebuild is idempotent.
 */
final class ExecutionReadModelProjection implements InlineProjection
{
    public const string NAME = 'orchestration.executions';

    /** @var EventSourcedRepository<ExecutionAggregate> */
    private readonly EventSourcedRepository $executions;

    public function __construct(EventSourcingSupport $support, private readonly ExecutionPersistenceProvider $readModel)
    {
        $this->executions = OrchestrationEventTypes::repository($support);
    }

    public function readModel(): ExecutionPersistenceProvider
    {
        return $this->readModel;
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function handles(): array
    {
        return OrchestrationEventTypes::types();
    }

    public function handle(StoredEvent $event): void
    {
        if ($event->aggregateType !== OrchestrationEventTypes::AGGREGATE_TYPE) {
            return;
        }

        $state = $this->executions->find($event->aggregateId)?->state();
        if ($state === null) {
            $this->readModel->delete($event->aggregateId);

            return;
        }

        $this->readModel->save($state);
    }

    public function reset(): void
    {
        do {
            $states = $this->readModel->find(new ExecutionCriteria(limit: 500));
            foreach ($states as $state) {
                $this->readModel->delete($state->correlationId);
            }
        } while ($states !== []);
    }
}
