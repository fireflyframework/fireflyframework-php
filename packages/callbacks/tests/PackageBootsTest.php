<?php

declare(strict_types=1);

use Firefly\Callbacks\CallbacksServiceProvider;
use Firefly\Callbacks\CallbacksSettings;
use Firefly\Callbacks\CallbacksWiringProvider;
use Firefly\Callbacks\Subscription\InMemorySubscriptionRegistry;
use Firefly\Callbacks\Subscription\SubscriptionRegistry;
use Firefly\Context\Boot\ApplicationContext;
use Firefly\Resilience\ResilienceServiceProvider;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Repository;

it('boots inert when firefly.callbacks.enabled is off', function () {
    $app = fireflyApplication(config: ['firefly' => []], providers: [CallbacksServiceProvider::class, CallbacksWiringProvider::class]);

    expect($app->make(ApplicationContext::class))->toBeInstanceOf(ApplicationContext::class)
        ->and($app->bound(CallbacksSettings::class))->toBeFalse();
});

it('binds the memory registry seeded from configuration when enabled', function () {
    $app = fireflyApplication(
        config: ['firefly' => [
            'callbacks' => ['enabled' => true, 'subscriptions' => [['id' => 's-1', 'url' => 'https://partner.example.com/hooks', 'event-types' => ['order.*']]]],
            'resilience' => ['method' => ['enabled' => false]],
        ]],
        providers: [ResilienceServiceProvider::class, CallbacksServiceProvider::class, CallbacksWiringProvider::class],
        bindings: [Repository::class => new CacheRepository(new ArrayStore)],
    );
    $registry = $app->make(SubscriptionRegistry::class);

    expect($registry)->toBeInstanceOf(InMemorySubscriptionRegistry::class)
        ->and($registry->find('s-1')?->url)->toBe('https://partner.example.com/hooks');
});
