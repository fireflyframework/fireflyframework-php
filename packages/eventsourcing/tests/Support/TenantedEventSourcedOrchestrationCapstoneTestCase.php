<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Tests\Support;

use Firefly\EventSourcing\Store\TenantResolver;
use Illuminate\Foundation\Application;

/**
 * The event-sourced orchestration capstone with firefly.eventsourcing.tenancy.enabled=true: the EventStore bean is a
 * TenantScopedEventStore over a TenantResolver the test switches. Executions are the framework's own records, so the
 * orchestration beans must work on the store beneath the scope, whatever tenant (or none) is current.
 */
abstract class TenantedEventSourcedOrchestrationCapstoneTestCase extends EventSourcedOrchestrationCapstoneTestCase
{
    public SwitchableTenantResolver $tenants;

    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return [...parent::configOverrides(), 'firefly.eventsourcing.tenancy.enabled' => true];
    }

    protected function defineFireflyEnvironment(Application $app): void
    {
        parent::defineFireflyEnvironment($app);

        $this->tenants = new SwitchableTenantResolver;
        $app->instance(TenantResolver::class, $this->tenants);
    }
}
