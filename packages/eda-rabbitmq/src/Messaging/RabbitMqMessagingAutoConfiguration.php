<?php

declare(strict_types=1);

namespace Firefly\Eda\Rabbitmq\Messaging;

use Firefly\Config\Config;
use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Eda\Rabbitmq\RabbitMqConnectionFactory;
use Firefly\Messaging\Container\ListenerInvoker;
use Firefly\Messaging\Container\RetryPolicy;
use Firefly\Messaging\MessageBrokerPort;
use Illuminate\Container\Container;
use Psr\Log\LoggerInterface;

/**
 * Binds the RabbitMQ MessageBrokerPort when firefly.messaging.provider=rabbitmq, at #[Order(900)] so it registers
 * before MessagingAutoConfiguration's #[ConditionalOnMissingBean] default — the RabbitMqAutoConfiguration precedent.
 * The connection is the event bus's (firefly.eda.rabbitmq.host|port|user|password|vhost, through
 * RabbitMqConnectionFactory); only the topology keys are messaging's. Nothing connects at boot.
 */
#[Configuration]
#[Order(900)]
final class RabbitMqMessagingAutoConfiguration
{
    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.messaging.provider', havingValue: 'rabbitmq')]
    public function messageBroker(Config $config, Container $container): MessageBrokerPort
    {
        $logger = $container->bound(LoggerInterface::class) ? $container->make(LoggerInterface::class) : null;
        $logger = $logger instanceof LoggerInterface ? $logger : null;

        return new RabbitMqMessageBroker(
            new PhpAmqpLibMessagingChannel(new RabbitMqConnectionFactory($config)),
            RetryPolicy::fromConfig($config),
            new ListenerInvoker(logger: $logger),
            $config->string('firefly.messaging.rabbitmq.exchange', 'firefly.messages'),
            $config->string('firefly.messaging.rabbitmq.dead-letter-exchange', ''),
            $config->int('firefly.messaging.rabbitmq.prefetch', 10),
            $config->bool('firefly.messaging.listener.dead-letter.enabled', true),
            $logger,
        );
    }
}
