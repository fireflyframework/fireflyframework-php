<?php

declare(strict_types=1);

namespace Firefly\Eda\Rabbitmq;

use Firefly\Eda\Bus\SubscriberRegistry;
use Firefly\Eda\EventEnvelope;
use Firefly\Eda\EventPublisher;
use Firefly\Eda\JsonSerializer;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use RuntimeException;

/**
 * The RabbitMQ EventPublisher over php-amqplib. publish() parses the destination as "exchange/routingKey", declares a
 * durable topic exchange (idempotent), and publishes a persistent JSON AMQPMessage (content-type application/json).
 * subscribe() records patterns on the shared SubscriberRegistry (the consumer feeds them). start()/stop() open/close
 * the connection+channel. $channelOverride exists ONLY for unit tests (a duck-typed PublishingChannel); production
 * wraps the real php-amqplib channel in a RabbitMqChannelAdapter. Reflection-free.
 */
final class RabbitMqEventPublisher implements EventPublisher
{
    private ?AMQPStreamConnection $connection = null;

    private ?PublishingChannel $channel;

    public function __construct(
        private readonly ?RabbitMqConnectionFactory $connectionFactory,
        private readonly SubscriberRegistry $registry,
        private readonly JsonSerializer $serializer,
        private readonly string $exchange = 'firefly.events',
        ?PublishingChannel $channelOverride = null,
    ) {
        $this->channel = $channelOverride;
    }

    /**
     * @return array{0: string, 1: string} [exchange, routingKey]
     */
    public static function parseDestination(string $destination, string $defaultExchange): array
    {
        $slash = strpos($destination, '/');
        if ($slash === false) {
            return [$defaultExchange, $destination];
        }

        return [substr($destination, 0, $slash), substr($destination, $slash + 1)];
    }

    public function subscribe(string $eventTypePattern, callable $handler): void
    {
        $this->registry->subscribe($eventTypePattern, $handler);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    public function publish(string $destination, string $eventType, array $payload, array $headers = []): void
    {
        [$exchange, $routingKey] = self::parseDestination($destination, $this->exchange);

        $channel = $this->channel();
        $channel->exchange_declare($exchange, 'topic', false, true, false);

        $body = $this->serializer->serialize(new EventEnvelope($eventType, $destination, $payload, $headers));
        $channel->basic_publish(
            new AMQPMessage($body, [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            ]),
            $exchange,
            $routingKey,
        );
    }

    public function start(): void
    {
        $this->channel();
    }

    public function stop(): void
    {
        $this->connection?->close();
        $this->connection = null;
        $this->channel = null;
    }

    private function channel(): PublishingChannel
    {
        if ($this->channel === null) {
            if ($this->connectionFactory === null) {
                throw new RuntimeException('RabbitMqEventPublisher has no connection factory and no channel override.');
            }
            $this->connection = $this->connectionFactory->connect();
            $this->channel = new RabbitMqChannelAdapter($this->connection->channel());
        }

        return $this->channel;
    }
}
