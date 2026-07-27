<?php

declare(strict_types=1);

namespace Firefly\Eda\Rabbitmq;

use Firefly\Actuator\Health\Health;
use Firefly\Actuator\Health\HealthIndicator;
use Firefly\Container\Attributes\Component;
use Throwable;

/** Pings the RabbitMQ connection; UP if it opens, DOWN (with the error detail) otherwise. Registered as `rabbitmq`. */
#[Component]
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
