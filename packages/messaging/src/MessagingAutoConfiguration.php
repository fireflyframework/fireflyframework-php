<?php

declare(strict_types=1);

namespace Firefly\Messaging;

use Firefly\Config\Config;
use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Messaging\Broker\InMemoryMessageBroker;
use Firefly\Messaging\Broker\QueueMessageBroker;
use Firefly\Messaging\DeadLetter\DatabaseDeadLetterStore;
use Firefly\Messaging\DeadLetter\DeadLetterStore;
use Firefly\Messaging\DeadLetter\InMemoryDeadLetterStore;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * Always-on messaging transport wiring. Selects the MessageBrokerPort adapter by firefly.messaging.provider
 * (`memory` default | `queue`; `kafka` and `rabbitmq` are bound by the eda-kafka / eda-rabbitmq adapters) and the
 * DeadLetterStore by firefly.messaging.dead-letter.store (`memory` default | `database`). #[Order(1000)] places it
 * after user definitions;
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
        $provider = $config->string('firefly.messaging.provider', 'memory');

        // kafka and rabbitmq are bound at #[Order(900)] by eda-kafka / eda-rabbitmq, before this configuration is
        // asked. Reaching here with either means that adapter did not bind — falling back to the in-memory broker
        // would publish into the void.
        if ($provider === 'kafka' || $provider === 'rabbitmq') {
            throw new ConfigurationException("firefly.messaging.provider={$provider} but no {$provider} MessageBrokerPort was bound; is the firefly/eda-{$provider} adapter installed and, for kafka, ext-rdkafka loaded?");
        }

        if ($provider === 'queue') {
            $connection = $config->has('firefly.messaging.queue.connection') ? $config->string('firefly.messaging.queue.connection') : null;
            $queue = $config->has('firefly.messaging.queue.name') ? $config->string('firefly.messaging.queue.name') : null;

            return new QueueMessageBroker($container, $connection, $queue);
        }

        return new InMemoryMessageBroker;
    }

    /**
     * firefly.messaging.dead-letter.store: `memory` (the default — in-process, lost on exit) or `database` (durable,
     * replayable, on firefly.messaging.dead-letter.connection). It takes the container, not Config and the connection
     * resolver, for the reason EdaAutoConfiguration::deadLetterStore() does: Config is autowired, never bound, so
     * Container::call() hands a nullable `?Config $config = null` its null default; and Laravel aliases
     * ConnectionResolverInterface to `db` even in an app that never registered DatabaseServiceProvider, so injecting
     * it would break every boot without a database. The resolver is made only when `database` is chosen. A direct
     * call with no container (the unit tests) yields the default.
     */
    #[Bean]
    #[ConditionalOnMissingBean(DeadLetterStore::class)]
    public function deadLetterStore(?Container $container = null): DeadLetterStore
    {
        if ($container === null) {
            return new InMemoryDeadLetterStore;
        }

        /** @var Config $config */
        $config = $container->make(Config::class);
        $store = $config->string('firefly.messaging.dead-letter.store', 'memory');

        if ($store === 'memory') {
            return new InMemoryDeadLetterStore;
        }

        if ($store !== 'database') {
            throw new ConfigurationException("firefly.messaging.dead-letter.store='{$store}' is not one of [memory|database].");
        }

        try {
            $connections = $container->make(ConnectionResolverInterface::class);
        } catch (BindingResolutionException $e) {
            throw new ConfigurationException('firefly.messaging.dead-letter.store=database needs a database connection resolver; register Laravel\'s DatabaseServiceProvider (every application has it) or bind your own DeadLetterStore.', previous: $e);
        }

        return new DatabaseDeadLetterStore(
            $connections,
            $config->has('firefly.messaging.dead-letter.connection') ? $config->string('firefly.messaging.dead-letter.connection') : null,
        );
    }
}
