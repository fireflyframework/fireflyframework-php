<?php

declare(strict_types=1);

namespace Firefly\Eda\Rabbitmq\Tests\Fixtures;

use Firefly\Eda\Rabbitmq\PublishingChannel;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * A pure-PHP PublishingChannel that RECORDS what RabbitMqEventPublisher::publish() would have put on the wire
 * instead of opening a socket — the FakeConsumingChannel precedent, on the publish side. Every publisher unit
 * test in this package injects this through the $channelOverride constructor parameter, so the exchange
 * declaration, the AMQP message (body + properties) and the routing key are all assertable with no broker.
 *
 * It is a FIXTURE CLASS rather than the anonymous class each test used to build for itself because two suites
 * now need it (the envelope/properties test and the tracing test), and a double that lives in one file is a
 * double the next test copies rather than reuses.
 */
final class CapturingPublishingChannel implements PublishingChannel
{
    /** @var list<array{0: string, 1: string, 2: bool, 3: bool, 4: bool}> every exchange_declare(), in order */
    public array $declared = [];

    /** @var list<array{0: AMQPMessage, 1: string, 2: string}> every [message, exchange, routingKey], in order */
    public array $published = [];

    public function exchange_declare(string $exchange, string $type, bool $passive, bool $durable, bool $autoDelete): void
    {
        $this->declared[] = [$exchange, $type, $passive, $durable, $autoDelete];
    }

    public function basic_publish(AMQPMessage $msg, string $exchange, string $routingKey): void
    {
        $this->published[] = [$msg, $exchange, $routingKey];
    }

    /**
     * The decoded envelope of the $index-th published message — the shape every assertion in this package
     * actually wants, JSON_THROW_ON_ERROR'd so a malformed body is a loud test failure rather than a null.
     *
     * @return array<string, mixed>
     */
    public function decoded(int $index = 0): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($this->published[$index][0]->getBody(), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
