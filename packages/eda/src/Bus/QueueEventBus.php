<?php

declare(strict_types=1);

namespace Firefly\Eda\Bus;

use Firefly\Eda\EventEnvelope;
use Firefly\Eda\EventPublisher;
use Firefly\Eda\Tracing\EdaTracing;
use Firefly\Eda\Tracing\NoOpEdaTracing;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher;

/**
 * The async EventPublisher adapter over illuminate/queue + illuminate/bus. publish() builds the envelope and
 * dispatches ONE DispatchEventJob onto the configured connection/queue, then returns immediately — delivery
 * happens on a queue worker (or inline under the `sync` driver). deliver() is the worker-side match+invoke over
 * the SubscriberRegistry that EventListenerWiringPass populated at this process's boot. Both crossings run
 * through the EdaTracing seam (NoOp by default): publish() is the PRODUCER side, whose headers — a traceparent
 * among them — ride the envelope through the queue; deliver() is the CONSUMER side on the worker, where that
 * traceparent is the only link back to the request that published.
 *
 * 🔴 The Bus Dispatcher is resolved from the container FRESH on every publish() — NEVER cached on $this — for the
 * exact reason DispatcherEventPublisher resolves 'events' fresh: Bus::fake() swaps the Dispatcher binding after
 * this object exists, and a cached dispatcher would publish straight past the fake, silently. Bound as a singleton
 * by the auto-config, so the wiring pass and the worker's handle() share ONE registry.
 */
final class QueueEventBus implements EventPublisher
{
    private readonly EdaTracing $tracing;

    public function __construct(
        private readonly SubscriberRegistry $registry,
        private readonly Container $container,
        private readonly ?string $connection,
        private readonly ?string $queue,
        ?EdaTracing $tracing = null,
    ) {
        $this->tracing = $tracing ?? new NoOpEdaTracing;
    }

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
        $this->tracing->tracePublish($destination, $eventType, $headers, function (array $headers) use ($destination, $eventType, $payload): void {
            $job = (new DispatchEventJob(new EventEnvelope($eventType, $destination, $payload, $headers)))
                ->onConnection($this->connection)
                ->onQueue($this->queue);

            // Resolved EVERY call so Bus::fake() intercepts — never hoist into the constructor.
            $this->container->make(Dispatcher::class)->dispatch($job);
        });
    }

    public function deliver(EventEnvelope $envelope): void
    {
        $this->tracing->traceConsume($envelope, fn (EventEnvelope $received) => $this->registry->deliver($received));
    }

    public function start(): void {}

    public function stop(): void {}
}
