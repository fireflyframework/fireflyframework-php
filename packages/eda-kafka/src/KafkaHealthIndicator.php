<?php

declare(strict_types=1);

namespace Firefly\Eda\Kafka;

use Firefly\Actuator\Health\Health;
use Firefly\Actuator\Health\HealthIndicator;
use Firefly\Container\Attributes\Component;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Throwable;

/**
 * Pings the Kafka broker's metadata; UP if it answers, DOWN (with the error detail, including an ext-missing
 * message) otherwise. Registered as `kafka`. OPT-IN (mirrors RabbitMqHealthIndicator's / PostgresHealthIndicator's
 * precedent — the STANDING SP-4 PRECEDENT): only active when firefly.eda.provider=kafka, so installing this
 * package without selecting it as the active eda provider never blocks /actuator/health on a broker the app isn't
 * using (and never requires ext-rdkafka to be present at all).
 */
#[Component]
#[ConditionalOnProperty(name: 'firefly.eda.provider', havingValue: 'kafka')]
final class KafkaHealthIndicator implements HealthIndicator
{
    public function __construct(private readonly string $brokers = '127.0.0.1:9092') {}

    public function health(): Health
    {
        if (! KafkaProducerFactory::available()) {
            return Health::down(['broker' => 'kafka', 'error' => 'ext-rdkafka is not loaded']);
        }

        try {
            $producer = (new KafkaProducerFactory($this->brokers))->producer();
            $producer->getMetadata(false, null, 2000); // touch the broker

            return Health::up(['broker' => 'kafka']);
        } catch (Throwable $e) {
            return Health::down(['broker' => 'kafka', 'error' => $e->getMessage()]);
        }
    }
}
