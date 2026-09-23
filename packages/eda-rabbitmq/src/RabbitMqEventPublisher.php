<?php

declare(strict_types=1);

namespace Firefly\Eda\Rabbitmq;

use Firefly\Eda\Bus\SubscriberRegistry;
use Firefly\Eda\EventEnvelope;
use Firefly\Eda\EventPublisher;
use Firefly\Eda\JsonSerializer;
use Firefly\Eda\Tracing\EdaTracing;
use Firefly\Eda\Tracing\NoOpEdaTracing;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use RuntimeException;

/**
 * The RabbitMQ EventPublisher over php-amqplib. publish() parses the destination as "exchange/routingKey", declares a
 * durable topic exchange (idempotent), and publishes a persistent JSON AMQPMessage (content-type application/json).
 * subscribe() records patterns on the shared SubscriberRegistry (the consumer feeds them). start()/stop() open/close
 * the connection+channel. $channelOverride exists ONLY for unit tests (a duck-typed PublishingChannel); production
 * wraps the real php-amqplib channel in a RabbitMqChannelAdapter. Reflection-free.
 *
 * publish() is routed through the EdaTracing seam, so the envelope that reaches the exchange carries the
 * producer span's `traceparent` — see the method's own docblock for why that seam owns the headers.
 */
final class RabbitMqEventPublisher implements EventPublisher
{
    private ?AMQPStreamConnection $connection = null;

    private ?PublishingChannel $channel;

    private readonly EdaTracing $tracing;

    public function __construct(
        private readonly ?RabbitMqConnectionFactory $connectionFactory,
        private readonly SubscriberRegistry $registry,
        private readonly JsonSerializer $serializer,
        private readonly string $exchange = 'firefly.events',
        ?PublishingChannel $channelOverride = null,
        ?EdaTracing $tracing = null,
    ) {
        $this->channel = $channelOverride;
        $this->tracing = $tracing ?? new NoOpEdaTracing;
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
     * Routed through EdaTracing::tracePublish() — the seam InMemoryEventBus and QueueEventBus have always
     * used, and the reason it takes the headers rather than returning them: propagation means WRITING
     * something on the envelope, so the implementation hands the send closure the headers to carry and the
     * adapter builds the message from those. Before this, the three broker adapters built their envelopes
     * themselves and no producer span existed on the wire at all; their CONSUME side was already traced
     * through SubscriberRegistrySink, so a `traceparent` a foreign producer put in the headers was continued
     * while one of our own was never written. A trace that stops at the broker is a trace of half a system.
     *
     * With tracing off the bound EdaTracing is the NoOp, which calls $send with the caller's headers
     * unchanged, so this path is byte-for-byte what it was.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    public function publish(string $destination, string $eventType, array $payload, array $headers = []): void
    {
        [$exchange, $routingKey] = self::parseDestination($destination, $this->exchange);

        $this->tracing->tracePublish($destination, $eventType, $headers, function (array $headers) use ($exchange, $routingKey, $destination, $eventType, $payload): void {
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
        });
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
