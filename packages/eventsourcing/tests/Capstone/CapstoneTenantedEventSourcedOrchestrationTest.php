<?php

declare(strict_types=1);

use Firefly\EventSourcing\Exception\TenantRequiredException;
use Firefly\EventSourcing\Orchestration\EventSourcedExecutionPersistenceProvider;
use Firefly\EventSourcing\Store\EventStore;
use Firefly\EventSourcing\Store\TenantScopedEventStore;
use Firefly\EventSourcing\Tests\Support\TenantedEventSourcedOrchestrationCapstoneTestCase;
use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Persistence\ExecutionPersistenceProvider;
use Firefly\Orchestration\Tests\CapstoneFixtures\Ledger;
use Firefly\Orchestration\Workflow\Engine\WorkflowEngine;

uses(TenantedEventSourcedOrchestrationCapstoneTestCase::class);

it('runs a workflow started under one tenant to completion under another tenant and under none', function (): void {
    /** @var TenantedEventSourcedOrchestrationCapstoneTestCase $this */
    $workflows = $this->app()->make(WorkflowEngine::class);
    /** @var TenantScopedEventStore $store asserted below: the bean stays scoped */
    $store = $this->app()->make(EventStore::class);

    $this->tenants->tenant = 'acme';
    $started = $workflows->start('onboarding', ['email' => 'ada@example.com'], correlationId: 'onb-tenanted');

    // Another tenant signals it: the execution is not hidden as globex's would be.
    $this->tenants->tenant = 'globex';
    $workflows->signal('onb-tenanted', 'email-verified', ['token' => 't-1']);
    usleep(5_000);

    // The timer sweep runs outside any request, with no tenant at all.
    $this->tenants->tenant = null;
    $workflows->resumeDue();

    $events = $store->inner()->load('onb-tenanted')->events;

    expect($this->app()->make(EventStore::class))->toBeInstanceOf(TenantScopedEventStore::class)
        ->and($this->app()->make(ExecutionPersistenceProvider::class))->toBeInstanceOf(EventSourcedExecutionPersistenceProvider::class)
        ->and($started->status)->toBe(ExecutionStatus::Waiting)
        ->and($workflows->find('onb-tenanted')?->status)->toBe(ExecutionStatus::Completed)
        ->and($this->app()->make(Ledger::class)->lines)->toBe(['hook start onboarding', 'register ada@example.com', 'verified', 'cooled off'])
        ->and($events)->not->toBeEmpty()
        // Executions are the framework's records: never stamped with whichever tenant happened to start them.
        ->and(array_values(array_unique(array_map(static fn ($event): ?string => $event->metadata->tenantId, $events))))->toBe([null])
        // The bean itself is still scoped: the unwrap is the orchestration beans' alone.
        ->and(fn () => $store->load('onb-tenanted'))->toThrow(TenantRequiredException::class);
});
