<?php

declare(strict_types=1);

namespace Firefly\Eda\Kafka\Messaging;

use Firefly\Config\Config;
use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Eda\Kafka\KafkaConsumerFactory;
use Firefly\Eda\Kafka\KafkaProducerFactory;
use Firefly\Messaging\Container\ListenerInvoker;
use Firefly\Messaging\Container\RetryPolicy;
use Firefly\Messaging\MessageBrokerPort;
use Illuminate\Container\Container;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Binds the Kafka MessageBrokerPort when firefly.messaging.provider=kafka. #[Order(900)] registers it before
 * MessagingAutoConfiguration (#[Order(1000)], #[ConditionalOnMissingBean]) is asked — the KafkaAutoConfiguration
 * precedent for the event bus. Fails fast at boot when ext-rdkafka is missing, never at the first publish.
 *
 * Brokers default to the event bus's (firefly.eda.kafka.brokers) so one Kafka cluster serves both transports
 * unless firefly.messaging.kafka.brokers says otherwise. Nothing connects at boot: consumers are built on the first
 * poll(), the producer on the first publish().
 */
#[Configuration]
#[Order(900)]
final class KafkaMessagingAutoConfiguration
{
    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.messaging.provider', havingValue: 'kafka')]
    public function messageBroker(Config $config, Container $container): MessageBrokerPort
    {
        if (! KafkaProducerFactory::available()) {
            throw new RuntimeException('firefly.messaging.provider=kafka but ext-rdkafka is not loaded. Install librdkafka + the rdkafka extension, or set another provider.');
        }

        $brokers = $config->has('firefly.messaging.kafka.brokers')
            ? $config->string('firefly.messaging.kafka.brokers')
            : $config->string('firefly.eda.kafka.brokers', '127.0.0.1:9092');
        $logger = $container->bound(LoggerInterface::class) ? $container->make(LoggerInterface::class) : null;
        $logger = $logger instanceof LoggerInterface ? $logger : null;

        return new KafkaMessageBroker(
            static fn (string $group): KafkaRecordClient => new RdKafkaRecordClient(new KafkaConsumerFactory($brokers, $group), new KafkaProducerFactory($brokers)),
            new RdKafkaRecordClient(new KafkaConsumerFactory($brokers), new KafkaProducerFactory($brokers)),
            RetryPolicy::fromConfig($config),
            new ListenerInvoker(logger: $logger),
            $config->string('firefly.messaging.kafka.group-id', 'firefly'),
            $config->string('firefly.messaging.kafka.dlt-suffix', '.DLT'),
            $config->bool('firefly.messaging.listener.dead-letter.enabled', true),
            logger: $logger,
        );
    }
}
