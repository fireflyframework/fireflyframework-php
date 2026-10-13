<?php

declare(strict_types=1);

namespace Firefly\Eda\Rabbitmq\Messaging;

/** One AMQP delivery, broker-agnostic: the queue it came from, its routing key, body, string headers and tag. */
final readonly class AmqpDelivery
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public string $queue,
        public string $routingKey,
        public string $body,
        public array $headers,
        public int $deliveryTag,
    ) {}
}
