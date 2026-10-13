<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Resilience\ResilienceRegistry;
use Firefly\Resilience\ResilienceServiceProvider;
use Firefly\Resilience\Store\InMemoryResilienceStore;
use Firefly\Resilience\Store\ResilienceStore;
use Firefly\Resilience\Tests\Fixtures\LocklessStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Application;

/**
 * The refusal through the REAL boot: the store bean is an eager singleton (BootPhase::EagerSingletons), so a
 * lockless default cache store fails the application's boot, not its first circuit-breaker call.
 *
 * @param  array<string, mixed>  $store
 */
function w4BootResilience(array $store): Application
{
    return fireflyApplication(
        config: ['firefly' => ['resilience' => ['store' => $store, 'method' => ['enabled' => false]]]],
        providers: [ResilienceServiceProvider::class],
        bindings: [Repository::class => new CacheRepository(new LocklessStore)],
    );
}

it('refuses to boot on a lockless default cache store', function () {
    expect(fn () => w4BootResilience([]))->toThrow(ConfigurationException::class, 'has no atomic locks');
});

it('boots on a lockless store when allow-lockless is set, and on memory regardless', function () {
    expect(w4BootResilience(['allow-lockless' => true])->make(ResilienceRegistry::class))->toBeInstanceOf(ResilienceRegistry::class)
        ->and(w4BootResilience(['provider' => 'memory'])->make(ResilienceStore::class))->toBeInstanceOf(InMemoryResilienceStore::class);
});
