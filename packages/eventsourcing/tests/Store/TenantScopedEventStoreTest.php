<?php

declare(strict_types=1);

use Firefly\EventSourcing\Event\EventMetadata;
use Firefly\EventSourcing\Exception\TenantRequiredException;
use Firefly\EventSourcing\Store\MemoryEventStore;
use Firefly\EventSourcing\Store\TenantResolver;
use Firefly\EventSourcing\Store\TenantScopedEventStore;
use Firefly\EventSourcing\Tests\Support\EventStoreContract;

final class MutableTenant implements TenantResolver
{
    public function __construct(public ?string $tenant = null) {}

    public function currentTenant(): ?string
    {
        return $this->tenant;
    }
}

it('stamps the current tenant, hides another tenant\'s stream and refuses to write into it', function (): void {
    $tenants = new MutableTenant('acme');
    $store = new TenantScopedEventStore(new MemoryEventStore, $tenants);
    $store->append('o-1', 'order', [EventStoreContract::event()], 0);

    expect($store->load('o-1')->events[0]->metadata->tenantId)->toBe('acme');

    $tenants->tenant = 'globex';

    expect($store->load('o-1')->isEmpty())->toBeTrue()
        ->and($store->exists('o-1'))->toBeFalse()
        ->and($store->version('o-1'))->toBe(0)
        ->and(fn () => $store->append('o-1', 'order', [EventStoreContract::event('order.paid')], 1))->toThrow(TenantRequiredException::class, 'o-1')
        ->and(fn () => $store->append('o-2', 'order', [EventStoreContract::event(metadata: new EventMetadata(tenantId: 'acme'))], 0))->toThrow(TenantRequiredException::class)
        // Projections read every tenant: the global stream is not scoped.
        ->and(EventStoreContract::ids($store->readAll()))->toBe(['o-1']);

    $tenants->tenant = null;

    expect(fn () => $store->load('o-1'))->toThrow(TenantRequiredException::class, 'no tenant');
});
