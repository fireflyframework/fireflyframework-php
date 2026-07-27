<?php

declare(strict_types=1);

namespace Firefly\Eda\Rabbitmq;

use PhpAmqpLib\Message\AMQPMessage;

/**
 * The minimal AMQP channel seam RabbitMqEventPublisher::publish() programs to — exactly the two php-amqplib
 * AMQPChannel calls it makes. Typed (not `object`) so PHPStan max resolves both call sites; RabbitMqChannelAdapter
 * wraps the real channel for production, a duck-typed double implements this directly in unit tests (no socket).
 */
interface PublishingChannel
{
    public function exchange_declare(string $exchange, string $type, bool $passive, bool $durable, bool $autoDelete): void;

    public function basic_publish(AMQPMessage $msg, string $exchange, string $routingKey): void;
}
