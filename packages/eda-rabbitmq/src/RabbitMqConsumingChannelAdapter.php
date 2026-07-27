<?php

declare(strict_types=1);

namespace Firefly\Eda\Rabbitmq;

use PhpAmqpLib\Channel\AMQPChannel;

/**
 * Wraps the real php-amqplib AMQPChannel behind ConsumingChannel so RabbitMqEventConsumer programs to a typed
 * seam instead of the vendor class's untyped (PHPDoc-only) parameters. Production-only; unit tests inject a
 * duck-typed ConsumingChannel double instead (no real socket). Mirrors RabbitMqChannelAdapter's precedent.
 */
final class RabbitMqConsumingChannelAdapter implements ConsumingChannel
{
    public function __construct(private readonly AMQPChannel $channel) {}

    public function exchange_declare(string $exchange, string $type, bool $passive, bool $durable, bool $autoDelete): void
    {
        $this->channel->exchange_declare($exchange, $type, $passive, $durable, $autoDelete);
    }

    public function queue_declare(string $queue, bool $durable, array $arguments): void
    {
        $this->channel->queue_declare($queue, false, $durable, false, false, false, $arguments);
    }

    public function queue_bind(string $queue, string $exchange, string $routingKey): void
    {
        $this->channel->queue_bind($queue, $exchange, $routingKey);
    }

    public function basic_qos(int $prefetchSize, int $prefetchCount, bool $global): void
    {
        $this->channel->basic_qos($prefetchSize, $prefetchCount, $global);
    }

    public function basic_consume(string $queue, callable $callback): void
    {
        $this->channel->basic_consume($queue, '', false, false, false, false, $callback);
    }

    public function wait(float $timeoutSeconds): void
    {
        $this->channel->wait(null, false, $timeoutSeconds);
    }

    public function basic_ack(int $deliveryTag): void
    {
        $this->channel->basic_ack($deliveryTag);
    }

    public function basic_nack(int $deliveryTag, bool $requeue): void
    {
        $this->channel->basic_nack($deliveryTag, false, $requeue);
    }
}
