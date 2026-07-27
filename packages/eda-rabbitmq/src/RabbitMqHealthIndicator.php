<?php

declare(strict_types=1);

namespace Firefly\Eda\Rabbitmq;

use Firefly\Actuator\Health\Health;
use Firefly\Actuator\Health\HealthIndicator;
use Firefly\Container\Attributes\Component;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Throwable;

/**
 * Pings the RabbitMQ connection; UP if it opens, DOWN (with the error detail) otherwise. Registered as `rabbitmq`.
 * OPT-IN (mirrors DbHealthIndicator's precedent): only active when firefly.eda.provider=rabbitmq, so installing
 * this package without selecting it as the active eda provider never blocks /actuator/health on a broker the app
 * isn't using (php-amqplib's default connect timeout is ~3s per attempt).
 */
#[Component]
#[ConditionalOnProperty(name: 'firefly.eda.provider', havingValue: 'rabbitmq')]
final class RabbitMqHealthIndicator implements HealthIndicator
{
    public function __construct(private readonly OpensConnection $connectionFactory) {}

    public function health(): Health
    {
        try {
            $connection = $this->connectionFactory->connect();
            $connection->close();

            return Health::up(['broker' => 'rabbitmq']);
        } catch (Throwable $e) {
            return Health::down(['broker' => 'rabbitmq', 'error' => $e->getMessage()]);
        }
    }
}
