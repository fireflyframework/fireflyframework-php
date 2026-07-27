<?php

declare(strict_types=1);

namespace Firefly\Eda\Rabbitmq;

use Firefly\Config\Config;
use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Eda\Bus\SubscriberRegistry;
use Firefly\Eda\EventPublisher;
use Firefly\Eda\JsonSerializer;

/**
 * Binds the RabbitMQ EventPublisher when firefly.eda.provider=rabbitmq. #[Order(900)] (below EdaAutoConfiguration's
 * 1000) guarantees this registers EventPublisher first, so eda's #[ConditionalOnMissingBean(EventPublisher::class)]
 * backs off — the scheduling-postgres precedent. The SubscriberRegistry is a shared singleton so the publisher, the
 * consumer, and EventListenerWiringPass all populate/read ONE registry.
 *
 * Every bean here — including rabbitConnectionFactory()/connectionOpener() — is gated behind
 * firefly.eda.provider=rabbitmq: RabbitMqHealthIndicator (the only thing that used to need the connection factory
 * while inactive) is now itself #[ConditionalOnProperty]-gated, so nothing in this package needs to resolve when
 * a different eda provider is active. Installing this package without opting in stays fully inert.
 */
#[Configuration]
#[Order(900)]
final class RabbitMqAutoConfiguration
{
    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.eda.provider', havingValue: 'rabbitmq')]
    public function rabbitConnectionFactory(Config $config): RabbitMqConnectionFactory
    {
        return new RabbitMqConnectionFactory($config);
    }

    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.eda.provider', havingValue: 'rabbitmq')]
    public function connectionOpener(RabbitMqConnectionFactory $factory): OpensConnection
    {
        return $factory;
    }

    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.eda.provider', havingValue: 'rabbitmq')]
    public function subscriberRegistry(): SubscriberRegistry
    {
        return new SubscriberRegistry;
    }

    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.eda.provider', havingValue: 'rabbitmq')]
    public function eventPublisher(Config $config, RabbitMqConnectionFactory $factory, SubscriberRegistry $registry): EventPublisher
    {
        return new RabbitMqEventPublisher(
            $factory,
            $registry,
            new JsonSerializer,
            $config->string('firefly.eda.rabbitmq.exchange', 'firefly.events'),
        );
    }
}
