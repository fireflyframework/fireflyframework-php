<?php

declare(strict_types=1);

namespace Firefly\Eda\Rabbitmq\Messaging;

use Closure;

/**
 * The AMQP operations RabbitMqMessageBroker needs, with no php-amqplib type in any signature — the
 * ConsumingChannel/PublishingChannel precedent, widened to what a raw-bytes listener container needs (headers on
 * publish, several consumed queues on one channel). PhpAmqpLibMessagingChannel is the real one; a fake drives the
 * unit tests. wait() answers false on a timeout instead of throwing.
 */
interface AmqpMessagingChannel
{
    public function declareExchange(string $exchange, string $type): void;

    /** @param  array<string, string>  $arguments */
    public function declareQueue(string $queue, array $arguments): void;

    public function bindQueue(string $queue, string $exchange, string $routingKey): void;

    public function qos(int $prefetch): void;

    /** @param  array<string, string>  $headers */
    public function publish(string $exchange, string $routingKey, string $body, array $headers): void;

    /** @param  Closure(AmqpDelivery): void  $callback */
    public function consume(string $queue, Closure $callback): void;

    public function wait(float $timeoutSeconds): bool;

    public function ack(int $deliveryTag): void;

    public function nack(int $deliveryTag, bool $requeue): void;

    public function close(): void;
}
