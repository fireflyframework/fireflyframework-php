<?php

declare(strict_types=1);

namespace Firefly\Eda\Rabbitmq;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Wraps the real php-amqplib AMQPChannel behind PublishingChannel so RabbitMqEventPublisher programs to a typed
 * seam instead of the vendor class's untyped (PHPDoc-only) parameters. Production-only; unit tests inject a
 * duck-typed PublishingChannel double instead (no real socket).
 */
final class RabbitMqChannelAdapter implements PublishingChannel
{
    public function __construct(private readonly AMQPChannel $channel) {}

    public function exchange_declare(string $exchange, string $type, bool $passive, bool $durable, bool $autoDelete): void
    {
        $this->channel->exchange_declare($exchange, $type, $passive, $durable, $autoDelete);
    }

    public function basic_publish(AMQPMessage $msg, string $exchange, string $routingKey): void
    {
        $this->channel->basic_publish($msg, $exchange, $routingKey);
    }
}
