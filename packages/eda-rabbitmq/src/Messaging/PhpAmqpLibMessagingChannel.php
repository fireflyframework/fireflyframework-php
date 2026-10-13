<?php

declare(strict_types=1);

namespace Firefly\Eda\Rabbitmq\Messaging;

use Closure;
use Firefly\Eda\Rabbitmq\OpensConnection;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * The real AmqpMessagingChannel over php-amqplib, on the connection RabbitMqConnectionFactory opens from the
 * firefly.eda.rabbitmq.* keys (one broker connection configuration for both transports). The connection opens
 * lazily on first use. Exchanges and queues are durable, messages persistent with a random message id a consumer
 * can deduplicate on, headers an AMQP table; consumption is manual-ack.
 */
final class PhpAmqpLibMessagingChannel implements AmqpMessagingChannel
{
    private ?AMQPStreamConnection $connection = null;

    private ?AMQPChannel $channel = null;

    public function __construct(private readonly OpensConnection $connections) {}

    public function declareExchange(string $exchange, string $type): void
    {
        $this->channel()->exchange_declare($exchange, $type, false, true, false);
    }

    public function declareQueue(string $queue, array $arguments): void
    {
        $this->channel()->queue_declare($queue, false, true, false, false, false, new AMQPTable($arguments));
    }

    public function bindQueue(string $queue, string $exchange, string $routingKey): void
    {
        $this->channel()->queue_bind($queue, $exchange, $routingKey);
    }

    public function qos(int $prefetch): void
    {
        $this->channel()->basic_qos(0, $prefetch, false);
    }

    public function publish(string $exchange, string $routingKey, string $body, array $headers): void
    {
        $message = new AMQPMessage($body, [
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            'message_id' => bin2hex(random_bytes(16)),
            'application_headers' => new AMQPTable($headers),
        ]);

        $this->channel()->basic_publish($message, $exchange, $routingKey);
    }

    public function consume(string $queue, Closure $callback): void
    {
        $this->channel()->basic_consume($queue, '', false, false, false, false, static function (AMQPMessage $message) use ($queue, $callback): void {
            $callback(new AmqpDelivery($queue, (string) $message->getRoutingKey(), $message->getBody(), self::headers($message), (int) $message->getDeliveryTag()));
        });
    }

    public function wait(float $timeoutSeconds): bool
    {
        try {
            $this->channel()->wait(null, false, $timeoutSeconds);

            return true;
        } catch (AMQPTimeoutException) {
            return false;
        }
    }

    public function ack(int $deliveryTag): void
    {
        $this->channel()->basic_ack($deliveryTag);
    }

    public function nack(int $deliveryTag, bool $requeue): void
    {
        $this->channel()->basic_nack($deliveryTag, false, $requeue);
    }

    public function close(): void
    {
        $this->channel?->close();
        $this->connection?->close();
        $this->channel = null;
        $this->connection = null;
    }

    /**
     * @return array<string, string>
     */
    private static function headers(AMQPMessage $message): array
    {
        if (! $message->has('application_headers')) {
            return [];
        }

        $table = $message->get('application_headers');
        $headers = [];
        foreach ($table instanceof AMQPTable ? $table->getNativeData() : [] as $name => $value) {
            if (is_scalar($value)) {
                $headers[(string) $name] = (string) $value;
            }
        }

        return $headers;
    }

    private function channel(): AMQPChannel
    {
        if ($this->channel === null) {
            $this->connection = $this->connections->connect();
            $this->channel = $this->connection->channel();
        }

        return $this->channel;
    }
}
