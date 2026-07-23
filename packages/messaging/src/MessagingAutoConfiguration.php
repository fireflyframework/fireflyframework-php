<?php

declare(strict_types=1);

namespace Firefly\Messaging;

use Firefly\Config\Config;
use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;
use Firefly\Messaging\Broker\InMemoryMessageBroker;
use Firefly\Messaging\Broker\QueueMessageBroker;
use Firefly\Messaging\DeadLetter\DeadLetterStore;
use Firefly\Messaging\DeadLetter\InMemoryDeadLetterStore;
use Illuminate\Container\Container;

/**
 * Always-on messaging transport wiring. Selects the MessageBrokerPort adapter by firefly.messaging.provider
 * (`memory` default | `queue`) and the DeadLetterStore default. #[Order(1000)] places it after user definitions;
 * each #[ConditionalOnMissingBean] lets an app bind its own and win. Both adapters are singletons (the #[Bean]
 * default), so the wiring pass and the queue worker share ONE subscriber set. Mirrors EdaAutoConfiguration.
 */
#[Configuration]
#[Order(1000)]
final class MessagingAutoConfiguration
{
    #[Bean]
    #[ConditionalOnMissingBean(MessageBrokerPort::class)]
    public function messageBroker(Config $config, Container $container): MessageBrokerPort
    {
        if ($config->string('firefly.messaging.provider', 'memory') === 'queue') {
            $connection = $config->has('firefly.messaging.queue.connection') ? $config->string('firefly.messaging.queue.connection') : null;
            $queue = $config->has('firefly.messaging.queue.name') ? $config->string('firefly.messaging.queue.name') : null;

            return new QueueMessageBroker($container, $connection, $queue);
        }

        return new InMemoryMessageBroker;
    }

    #[Bean]
    #[ConditionalOnMissingBean(DeadLetterStore::class)]
    public function deadLetterStore(): DeadLetterStore
    {
        return new InMemoryDeadLetterStore;
    }
}
