<?php

declare(strict_types=1);

namespace Firefly\Callbacks\Tests\Support;

use Firefly\Callbacks\CallbacksServiceProvider;
use Firefly\Callbacks\CallbacksWiringProvider;
use Firefly\Eda\Bus\InMemoryEventBus;
use Firefly\Eda\Bus\SubscriberRegistry;
use Firefly\Eda\EventPublisher;
use Firefly\Resilience\ResilienceServiceProvider;
use Firefly\Testing\FireflyTestCase;
use Illuminate\Foundation\Application;

/**
 * Callbacks through the real boot: resilience (the breaker's store, on the array cache), both callbacks providers,
 * one seeded subscription for `order.created`, and the REAL in-memory event bus bound before boot, so publishing an
 * event runs the wiring pass's subscription and the dispatcher exactly as a queue or broker consumer would. Retries
 * wait 0s so a failing endpoint costs no wall-clock time.
 */
abstract class CallbacksCapstoneTestCase extends FireflyTestCase
{
    public InMemoryEventBus $events;

    /** @return list<class-string> */
    protected function fireflyProviders(): array
    {
        return [ResilienceServiceProvider::class, CallbacksServiceProvider::class, CallbacksWiringProvider::class];
    }

    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return [
            'cache.default' => 'array',
            'firefly.resilience.method.enabled' => false,
            'firefly.callbacks.enabled' => true,
            'firefly.callbacks.event-types' => ['order.*'],
            'firefly.callbacks.retry.initial-delay' => 0,
            'firefly.callbacks.subscriptions' => [[
                'id' => 'sub-orders',
                'name' => 'partner orders',
                'url' => 'https://partner.example.com/hooks/orders',
                'event-types' => ['order.created'],
                'secret' => 'shared-secret',
            ]],
        ];
    }

    protected function defineFireflyEnvironment(Application $app): void
    {
        $this->events = new InMemoryEventBus(new SubscriberRegistry);
        $app->instance(EventPublisher::class, $this->events);
    }
}
