<?php

declare(strict_types=1);

namespace Firefly\Messaging\Boot;

use Closure;
use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;
use Firefly\Messaging\DeadLetter\DeadLetterStore;
use Firefly\Messaging\DeadLetter\RetryingMessageHandler;
use Firefly\Messaging\Listener\MessageListenerDescriptor;
use Firefly\Messaging\Listener\MessageListenerManifest;
use Firefly\Messaging\Message;
use Firefly\Messaging\MessageBrokerPort;
use Illuminate\Container\Container;

/**
 * Subscribes the app's compiled #[MessageListener]s onto the resolved MessageBrokerPort at phase WiringPasses/1000,
 * then starts the broker. Runs in EVERY process (web + worker), so the share-nothing queue worker rebuilds the
 * identical subscriber set from the same manifest. Each subscriber is wrapped by RetryingMessageHandler with the
 * DESCRIPTOR's OWN retries/retryDelay/deadLetterTopic (the per-listener policy, unlike eda's config-driven one) +
 * the bound DeadLetterStore, and resolves its target bean FRESH on every dispatch (never caching it). start() is
 * called last so the in-memory broker is publish-ready after boot (a no-op for the queue adapter).
 */
final class MessageListenerWiringPass implements BootPass
{
    public function phase(): BootPhase
    {
        return BootPhase::WiringPasses;
    }

    public function order(): int
    {
        return 0;
    }

    public function run(BootContext $context): void
    {
        $container = $context->container;

        /** @var MessageListenerManifest $manifest */
        $manifest = $container->make(MessageListenerManifest::class);
        /** @var MessageBrokerPort $broker */
        $broker = $container->make(MessageBrokerPort::class);
        /** @var DeadLetterStore $dlq */
        $dlq = $container->make(DeadLetterStore::class);

        foreach ($manifest->all() as $descriptor) {
            $handler = RetryingMessageHandler::wrap(
                $this->invoker($container, $descriptor),
                $descriptor->retries,
                $descriptor->retryDelay,
                $descriptor->deadLetterTopic,
                $dlq,
            );

            $broker->subscribe($descriptor->topic, $handler, $descriptor->group);
        }

        $broker->start();
    }

    private function invoker(Container $container, MessageListenerDescriptor $descriptor): Closure
    {
        return static function (Message $message) use ($container, $descriptor): void {
            $bean = $container->make($descriptor->class);
            $method = $descriptor->method;
            $bean->{$method}($message);
        };
    }
}
