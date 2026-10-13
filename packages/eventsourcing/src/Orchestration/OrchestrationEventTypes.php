<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Orchestration;

use Firefly\Domain\DomainEvent;
use Firefly\EventSourcing\Aggregate\EventSourcedRepository;
use Firefly\EventSourcing\Aggregate\EventSourcingSupport;
use Firefly\EventSourcing\Event\EventTypeDescriptor;
use Firefly\EventSourcing\Event\EventTypeRegistry;
use Firefly\EventSourcing\Orchestration\Event\ExecutionCheckpointed;
use Firefly\EventSourcing\Orchestration\Event\ExecutionDeleted;
use Firefly\EventSourcing\Orchestration\Event\ExecutionStarted;
use Firefly\EventSourcing\Orchestration\Event\ExecutionStatusChanged;
use Firefly\EventSourcing\Orchestration\Event\SearchAttributesChanged;
use Firefly\EventSourcing\Orchestration\Event\StepResultRecorded;
use Firefly\EventSourcing\Orchestration\Event\StepStatusChanged;

/**
 * The orchestration execution's event types. They are framework events, outside every application's scan paths, so
 * they are registered here by hand rather than compiled from #[EventType]; registering twice is a no-op.
 */
final class OrchestrationEventTypes
{
    public const string AGGREGATE_TYPE = 'orchestration.execution';

    /** @var array<class-string<DomainEvent>, string> */
    public const array TYPES = [
        ExecutionStarted::class => 'orchestration.execution.started',
        ExecutionStatusChanged::class => 'orchestration.execution.status-changed',
        StepStatusChanged::class => 'orchestration.execution.step-status-changed',
        StepResultRecorded::class => 'orchestration.execution.step-result-recorded',
        SearchAttributesChanged::class => 'orchestration.execution.search-attributes-changed',
        ExecutionCheckpointed::class => 'orchestration.execution.checkpointed',
        ExecutionDeleted::class => 'orchestration.execution.deleted',
    ];

    public static function register(EventTypeRegistry $registry): void
    {
        foreach (self::TYPES as $class => $type) {
            $registry->register(new EventTypeDescriptor($class, $type, 1, true));
        }
    }

    /** @return list<string> */
    public static function types(): array
    {
        return array_values(self::TYPES);
    }

    /** @return EventSourcedRepository<ExecutionAggregate> */
    public static function repository(EventSourcingSupport $support): EventSourcedRepository
    {
        self::register($support->mapper->types());

        return $support->repository(ExecutionAggregate::class);
    }
}
