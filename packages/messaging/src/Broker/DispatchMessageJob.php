<?php

declare(strict_types=1);

namespace Firefly\Messaging\Broker;

use Firefly\Messaging\Exception\MessagingException;
use Firefly\Messaging\Message;
use Firefly\Messaging\MessageBrokerPort;
use Illuminate\Bus\Queueable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * The async delivery unit: QueueMessageBroker::publish() dispatches ONE of these carrying the raw (topic, value,
 * key, headers), and the queue worker runs handle() on its OWN process. handle() resolves the process-local
 * MessageBrokerPort singleton — whose subscriptions were populated during THIS worker's boot by
 * MessageListenerWiringPass from the same compiled manifest — and calls deliver(), so the share-nothing worker
 * invokes the identical subscriber set. All fields are scalars/arrays, so Laravel's default job serialization
 * carries them with no custom logic.
 */
final class DispatchMessageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly string $topic,
        public readonly string $value,
        public readonly ?string $key,
        public readonly array $headers,
    ) {}

    public function handle(Container $container): void
    {
        $broker = $this->resolveBroker($container);

        if (! $broker instanceof QueueMessageBroker) {
            throw new MessagingException(
                'DispatchMessageJob requires the queue MessageBrokerPort (QueueMessageBroker); got '.$broker::class.'. '
                .'Set firefly.messaging.provider=queue so the async adapter is bound on the worker.',
                'MESSAGING_QUEUE_MISCONFIGURED',
            );
        }

        $broker->deliver(new Message($this->topic, $this->value, $this->key, $this->headers));
    }

    /**
     * Resolves the bound MessageBrokerPort as its CONTRACT type. Routing the resolution through this interface-typed
     * boundary keeps the misconfiguration guard above honest under static analysis: an analyzer that resolves the
     * container's default (in-memory) binding would otherwise narrow make(MessageBrokerPort::class) to the concrete
     * InMemoryMessageBroker and flag the `instanceof QueueMessageBroker` check — which only holds when
     * firefly.messaging.provider=queue — as statically dead. Runtime behaviour is identical to calling make() inline.
     */
    private function resolveBroker(Container $container): MessageBrokerPort
    {
        return $container->make(MessageBrokerPort::class);
    }
}
