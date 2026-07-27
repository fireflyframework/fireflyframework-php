<?php

declare(strict_types=1);

namespace Firefly\Eda\Rabbitmq;

use Firefly\Eda\Consumer\EventConsumer;
use Firefly\Eda\Consumer\ReceivedEnvelope;
use Firefly\Eda\JsonSerializer;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use RuntimeException;

/**
 * The long-lived RabbitMQ EventConsumer over php-amqplib, behind the typed ConsumingChannel seam (unit tests fake
 * it — no socket, the RabbitMqEventPublisher/PublishingChannel precedent). subscribe() declares the topic exchange
 * + a DLX topic exchange + a durable work queue carrying an x-dead-letter-exchange arg — so a broker-native
 * nack(requeue:false) (an exhausted retry) is routed by RabbitMQ itself to the DLX, rather than the in-memory
 * DeadLetterStore the M9 adapters use — then binds each destination pattern as an AMQP routing key: a bare '*' or
 * '**' broadens to the AMQP catch-all '#'; anything else (including the common "word.*" shape TopicSubscriptionResolver
 * emits) already reads as a valid AMQP topic pattern unchanged, so it passes through as-is. SubscriberRegistry's
 * fnmatch still does the fine-grained post-receipt filter (TopicSubscriptionResolver's documented contract), so
 * broker-level over-matching is harmless.
 *
 * poll() registers exactly ONE basic_consume callback (idempotent across calls — $consuming guards it) that
 * buffers the next delivered envelope, then blocks in wait() for up to $timeoutMs (converted to seconds — verified
 * against the installed php-amqplib v3.7.4: AbstractChannel::wait()'s $timeout is compared directly against
 * microtime(true) deltas throughout AbstractConnection::wait_channel()/wait_frame(), i.e. SECONDS, not
 * milliseconds). An AMQPTimeoutException means "no message this tick" -> null. ReceivedEnvelope's deliveryTag is a
 * plain int (not the vendor AMQPMessage), so ack()/nack() replay it against THIS consumer's own channel — never a
 * channel reached through the message — keeping ReceivedEnvelope broker-agnostic.
 */
final class RabbitMqEventConsumer implements EventConsumer
{
    private ?AMQPStreamConnection $connection = null;

    private ?ConsumingChannel $channel;

    private ?ReceivedEnvelope $pending = null;

    private bool $consuming = false;

    public function __construct(
        private readonly ?RabbitMqConnectionFactory $connectionFactory,
        private readonly JsonSerializer $serializer,
        private readonly string $exchange = 'firefly.events',
        private readonly string $queue = 'firefly.eda',
        private readonly string $deadLetterExchange = 'firefly.events.dlx',
        private readonly int $prefetch = 10,
        ?ConsumingChannel $channelOverride = null,
    ) {
        $this->channel = $channelOverride;
    }

    /**
     * @param  list<string>  $destinations
     */
    public function subscribe(array $destinations): void
    {
        $channel = $this->channel();
        $channel->exchange_declare($this->exchange, 'topic', false, true, false);
        $channel->exchange_declare($this->deadLetterExchange, 'topic', false, true, false);
        $channel->queue_declare($this->queue, true, ['x-dead-letter-exchange' => ['S', $this->deadLetterExchange]]);
        $channel->basic_qos(0, $this->prefetch, false);

        foreach ($destinations as $pattern) {
            $channel->queue_bind($this->queue, $this->exchange, $this->toRoutingKey($pattern));
        }
    }

    public function start(): void
    {
        $this->channel();
    }

    public function poll(int $timeoutMs): ?ReceivedEnvelope
    {
        $channel = $this->channel();

        if (! $this->consuming) {
            $channel->basic_consume($this->queue, function (AMQPMessage $msg): void {
                $this->pending = new ReceivedEnvelope(
                    $this->serializer->deserialize($msg->getBody()),
                    $msg->getDeliveryTag(),
                );
            });
            $this->consuming = true;
        }

        try {
            $channel->wait($timeoutMs / 1000);
        } catch (AMQPTimeoutException) {
            return null;
        }

        $received = $this->pending;
        $this->pending = null;

        return $received;
    }

    public function ack(ReceivedEnvelope $received): void
    {
        $this->channel()->basic_ack($this->deliveryTag($received));
    }

    public function nack(ReceivedEnvelope $received, bool $requeue = true): void
    {
        $this->channel()->basic_nack($this->deliveryTag($received), $requeue);
    }

    public function stop(): void
    {
        $this->connection?->close();
        $this->connection = null;
        $this->channel = null;
        $this->consuming = false;
    }

    private function deliveryTag(ReceivedEnvelope $received): int
    {
        if (! is_int($received->deliveryTag)) {
            throw new RuntimeException('ReceivedEnvelope::$deliveryTag must be the AMQP delivery tag (int).');
        }

        return $received->deliveryTag;
    }

    private function toRoutingKey(string $pattern): string
    {
        return $pattern === '*' || $pattern === '**' ? '#' : $pattern;
    }

    private function channel(): ConsumingChannel
    {
        if ($this->channel === null) {
            if ($this->connectionFactory === null) {
                throw new RuntimeException('RabbitMqEventConsumer has no connection factory and no channel override.');
            }
            $this->connection = $this->connectionFactory->connect();
            $this->channel = new RabbitMqConsumingChannelAdapter($this->connection->channel());
        }

        return $this->channel;
    }
}
