<?php

declare(strict_types=1);

namespace Firefly\Eda\Rabbitmq;

use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * The minimal AMQP channel seam RabbitMqEventConsumer programs to — exactly the php-amqplib AMQPChannel calls
 * subscribe()/poll()/ack()/nack() make. Typed (not `object`) so PHPStan max resolves every call site, mirroring
 * PublishingChannel's precedent from the publisher side. RabbitMqConsumingChannelAdapter wraps the real channel
 * for production; unit tests inject a duck-typed double directly (no socket, no real AMQPMessage delivery).
 */
interface ConsumingChannel
{
    public function exchange_declare(string $exchange, string $type, bool $passive, bool $durable, bool $autoDelete): void;

    /** @param array<string, array{0: string, 1: mixed}> $arguments AMQP table entries, type-tagged e.g. ['S', 'value'] */
    public function queue_declare(string $queue, bool $durable, array $arguments): void;

    public function queue_bind(string $queue, string $exchange, string $routingKey): void;

    public function basic_qos(int $prefetchSize, int $prefetchCount, bool $global): void;

    /** @param callable(AMQPMessage): void $callback */
    public function basic_consume(string $queue, callable $callback): void;

    /** @throws AMQPTimeoutException when no message arrives within $timeoutSeconds */
    public function wait(float $timeoutSeconds): void;

    public function basic_ack(int $deliveryTag): void;

    public function basic_nack(int $deliveryTag, bool $requeue): void;
}
