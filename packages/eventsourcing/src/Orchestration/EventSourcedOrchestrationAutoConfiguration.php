<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Orchestration;

use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Lazy;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\EventSourcing\Aggregate\EventSourcingSupport;
use Firefly\EventSourcing\EventSourcingAutoConfiguration;
use Firefly\EventSourcing\EventSourcingSettings;
use Firefly\EventSourcing\Store\TenantScopedEventStore;
use Firefly\Orchestration\Persistence\DatabaseExecutionPersistenceProvider;
use Firefly\Orchestration\Persistence\ExecutionPersistenceProvider;
use Firefly\Orchestration\Persistence\MemoryExecutionPersistenceProvider;
use Illuminate\Container\Container;

/**
 * firefly.orchestration.persistence.provider=event-sourced. #[Order(900)] runs it before OrchestrationAutoConfiguration
 * (#[Order(1000)]), whose own ExecutionPersistenceProvider bean then backs off #[ConditionalOnMissingBean]; an
 * application that binds its own provider still wins over both.
 *
 * The executions live in the event store firefly.eventsourcing.store.* selects. The read model is W2's
 * `firefly_orchestration_*` tables on the SAME connection for the database store (so a save is one transaction), or
 * W2's memory provider for the memory store; orchestration's own persistence.connection is not read. Executions are
 * the framework's own records, read across tenants, so both beans work on the store beneath a TenantScopedEventStore.
 */
#[Configuration]
#[Order(900)]
#[ConditionalOnProperty(name: 'firefly.orchestration.persistence.provider', havingValue: 'event-sourced')]
final class EventSourcedOrchestrationAutoConfiguration
{
    #[Bean]
    #[Lazy]
    #[ConditionalOnMissingBean(ExecutionReadModelProjection::class)]
    public function executionReadModelProjection(EventSourcingSupport $support, EventSourcingSettings $settings, Container $app): ExecutionReadModelProjection
    {
        $readModel = $settings->storeProvider === 'database'
            ? new DatabaseExecutionPersistenceProvider(EventSourcingAutoConfiguration::connection($app, $settings->connection, 'firefly.orchestration.persistence.provider'))
            : new MemoryExecutionPersistenceProvider;

        return new ExecutionReadModelProjection(self::untenanted($support), $readModel);
    }

    #[Bean]
    #[Lazy]
    #[ConditionalOnMissingBean(ExecutionPersistenceProvider::class)]
    public function eventSourcedExecutionPersistenceProvider(EventSourcingSupport $support, ExecutionReadModelProjection $readModel, EventSourcingSettings $settings, Container $app): ExecutionPersistenceProvider
    {
        $connection = $settings->storeProvider === 'database'
            ? EventSourcingAutoConfiguration::connection($app, $settings->connection, 'firefly.orchestration.persistence.provider')
            : null;

        return new EventSourcedExecutionPersistenceProvider(self::untenanted($support), $readModel->readModel(), $connection);
    }

    private static function untenanted(EventSourcingSupport $support): EventSourcingSupport
    {
        return $support->store instanceof TenantScopedEventStore
            ? new EventSourcingSupport($support->store->inner(), $support->mapper, $support->snapshots, $support->snapshotPolicy, $support->metadata, $support->logger)
            : $support;
    }
}
