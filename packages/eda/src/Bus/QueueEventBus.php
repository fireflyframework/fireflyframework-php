<?php

declare(strict_types=1);

namespace Firefly\Eda\Bus;

use Firefly\Eda\EventEnvelope;
use Firefly\Eda\EventPublisher;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher;

/**
 * The async EventPublisher adapter over illuminate/queue + illuminate/bus. publish() builds the envelope and
 * dispatches ONE DispatchEventJob onto the configured connection/queue, then returns immediately — delivery
 * happens on a queue worker (or inline under the `sync` driver). deliver() is the worker-side match+invoke over
 * the SubscriberRegistry that EventListenerWiringPass populated at this process's boot.
 *
 * 🔴 The Bus Dispatcher is resolved from the container FRESH on every publish() — NEVER cached on $this — for the
 * exact reason DispatcherEventPublisher resolves 'events' fresh: Bus::fake() swaps the Dispatcher binding after
 * this object exists, and a cached dispatcher would publish straight past the fake, silently. Bound as a singleton
 * by the auto-config, so the wiring pass and the worker's handle() share ONE registry.
 */
final class QueueEventBus implements EventPublisher
{
    public function __construct(
        private readonly SubscriberRegistry $registry,
        private readonly Container $container,
        private readonly ?string $connection,
        private readonly ?string $queue,
    ) {}

    public function subscribe(string $eventTypePattern, callable $handler): void
    {
        $this->registry->subscribe($eventTypePattern, $handler);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    public function publish(string $destination, string $eventType, array $payload, array $headers = []): void
    {
        $job = (new DispatchEventJob(new EventEnvelope($eventType, $destination, $payload, $headers)))
            ->onConnection($this->connection)
            ->onQueue($this->queue);

        // Resolved EVERY call so Bus::fake() intercepts — never hoist into the constructor.
        $this->container->make(Dispatcher::class)->dispatch($job);
    }

    public function deliver(EventEnvelope $envelope): void
    {
        $this->registry->deliver($envelope);
    }

    public function start(): void {}

    public function stop(): void {}
}
