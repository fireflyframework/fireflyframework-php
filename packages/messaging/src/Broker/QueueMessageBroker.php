<?php

declare(strict_types=1);

namespace Firefly\Messaging\Broker;

use Firefly\Messaging\Message;
use Firefly\Messaging\MessageBrokerPort;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher;

/**
 * The async MessageBrokerPort adapter over illuminate/queue + illuminate/bus. publish() dispatches ONE
 * DispatchMessageJob onto the configured connection/queue and returns immediately; deliver() is the worker-side
 * invoke over the subscribers MessageListenerWiringPass registered at this process's boot. The queue path
 * broadcasts to a topic's subscribers — consumer-group round-robin is an in-memory-only nicety here, because
 * genuine competing-consumer semantics need a real broker (SP-4); a subscribe()'s $group is accepted and ignored.
 *
 * 🔴 The Bus Dispatcher is resolved FRESH on every publish() — never cached — so Bus::fake() intercepts it (same
 * rule as QueueEventBus / DispatcherEventPublisher). Bound as a singleton by the auto-config, so the wiring pass
 * and the worker's handle() share ONE subscriber set.
 */
final class QueueMessageBroker implements MessageBrokerPort
{
    /** @var array<string, list<callable>> */
    private array $subscribers = [];

    public function __construct(
        private readonly Container $container,
        private readonly ?string $connection,
        private readonly ?string $queue,
    ) {}

    public function subscribe(string $topic, callable $handler, ?string $group = null): void
    {
        $this->subscribers[$topic][] = $handler;
    }

    /**
     * @param  array<string, string>  $headers
     */
    public function publish(string $topic, string $value, ?string $key = null, array $headers = []): void
    {
        $job = (new DispatchMessageJob($topic, $value, $key, $headers))
            ->onConnection($this->connection)
            ->onQueue($this->queue);

        // Resolved EVERY call so Bus::fake() intercepts — never hoist into the constructor.
        $this->container->make(Dispatcher::class)->dispatch($job);
    }

    public function deliver(Message $message): void
    {
        foreach ($this->subscribers[$message->topic] ?? [] as $handler) {
            $handler($message);
        }
    }

    public function start(): void {}

    public function stop(): void {}
}
