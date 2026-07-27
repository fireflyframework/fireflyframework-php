<?php

declare(strict_types=1);

namespace Firefly\Eda\Kafka;

use Firefly\Config\Config;
use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Eda\Bus\SubscriberRegistry;
use Firefly\Eda\Consumer\EventConsumer;
use Firefly\Eda\EventPublisher;
use Firefly\Eda\JsonSerializer;
use RuntimeException;

/**
 * Binds the Kafka EventPublisher + EventConsumer when firefly.eda.provider=kafka. #[Order(900)] (below
 * EdaAutoConfiguration's 1000) guarantees this registers EventPublisher first, so eda's
 * #[ConditionalOnMissingBean(EventPublisher::class)] backs off — the RabbitMQ/Postgres precedent. The
 * SubscriberRegistry is a shared singleton so the publisher, the consumer, and EventListenerWiringPass all
 * populate/read ONE registry.
 *
 * eventPublisher() and eventConsumer() both FAIL FAST with a clear RuntimeException when provider=kafka but
 * ext-rdkafka is not loaded — a deliberate boot-time error (never a silent no-op) so a misconfigured deploy learns
 * about the missing extension immediately rather than at the first publish()/poll() call. Every bean here is
 * gated behind firefly.eda.provider=kafka: KafkaHealthIndicator (the only thing that would otherwise need to
 * resolve while inactive) is itself #[ConditionalOnProperty]-gated too, so nothing in this package needs to
 * resolve — or require ext-rdkafka — when a different eda provider is active. Installing this package without
 * opting in stays fully inert.
 */
#[Configuration]
#[Order(900)]
final class KafkaAutoConfiguration
{
    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.eda.provider', havingValue: 'kafka')]
    public function subscriberRegistry(): SubscriberRegistry
    {
        return new SubscriberRegistry;
    }

    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.eda.provider', havingValue: 'kafka')]
    public function eventPublisher(Config $config, SubscriberRegistry $registry): EventPublisher
    {
        if (! KafkaProducerFactory::available()) {
            throw new RuntimeException('firefly.eda.provider=kafka but ext-rdkafka is not loaded. Install librdkafka + the rdkafka extension, or set another provider.');
        }

        return new KafkaEventPublisher(
            new KafkaProducerFactory($config->string('firefly.eda.kafka.brokers', '127.0.0.1:9092')),
            $registry,
            new JsonSerializer,
        );
    }

    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.eda.provider', havingValue: 'kafka')]
    public function eventConsumer(Config $config): EventConsumer
    {
        if (! KafkaConsumerFactory::available()) {
            throw new RuntimeException('firefly.eda.provider=kafka but ext-rdkafka is not loaded. Install librdkafka + the rdkafka extension, or set another provider.');
        }

        $brokers = $config->string('firefly.eda.kafka.brokers', '127.0.0.1:9092');

        return new KafkaEventConsumer(
            new RdKafkaConsumerClient(
                new KafkaConsumerFactory($brokers, $config->string('firefly.eda.consumer.group_id', 'firefly')),
                new KafkaProducerFactory($brokers),
                new JsonSerializer,
            ),
        );
    }
}
