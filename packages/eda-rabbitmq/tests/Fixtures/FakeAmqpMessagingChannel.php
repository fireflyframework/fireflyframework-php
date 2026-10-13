<?php

declare(strict_types=1);

namespace Firefly\Eda\Rabbitmq\Tests\Fixtures;

use Closure;
use Firefly\Eda\Rabbitmq\Messaging\AmqpDelivery;
use Firefly\Eda\Rabbitmq\Messaging\AmqpMessagingChannel;

/** An AMQP channel without a broker: records the topology and the acks, delivers what a test queues. */
final class FakeAmqpMessagingChannel implements AmqpMessagingChannel
{
    /** @var array<string, string> exchange => type */
    public array $exchanges = [];

    /** @var array<string, array<string, string>> queue => arguments */
    public array $queues = [];

    /** @var list<array{0: string, 1: string, 2: string}> queue, exchange, routing key */
    public array $bindings = [];

    public ?int $prefetch = null;

    /** @var list<array{exchange: string, routingKey: string, body: string, headers: array<string, string>}> */
    public array $published = [];

    /** @var list<int> */
    public array $acked = [];

    /** @var list<array{0: int, 1: bool}> */
    public array $nacked = [];

    public bool $closed = false;

    /** @var array<string, Closure(AmqpDelivery): void> */
    private array $consumers = [];

    /** @var list<AmqpDelivery> */
    private array $inbound = [];

    /**
     * @param  array<string, string>  $headers
     */
    public function deliver(string $queue, string $routingKey, string $body, array $headers = [], int $tag = 1): void
    {
        $this->inbound[] = new AmqpDelivery($queue, $routingKey, $body, $headers, $tag);
    }

    public function declareExchange(string $exchange, string $type): void
    {
        $this->exchanges[$exchange] = $type;
    }

    public function declareQueue(string $queue, array $arguments): void
    {
        $this->queues[$queue] = $arguments;
    }

    public function bindQueue(string $queue, string $exchange, string $routingKey): void
    {
        $this->bindings[] = [$queue, $exchange, $routingKey];
    }

    public function qos(int $prefetch): void
    {
        $this->prefetch = $prefetch;
    }

    public function publish(string $exchange, string $routingKey, string $body, array $headers): void
    {
        $this->published[] = ['exchange' => $exchange, 'routingKey' => $routingKey, 'body' => $body, 'headers' => $headers];
    }

    public function consume(string $queue, Closure $callback): void
    {
        $this->consumers[$queue] = $callback;
    }

    public function wait(float $timeoutSeconds): bool
    {
        $delivery = array_shift($this->inbound);
        if ($delivery === null) {
            return false;
        }

        ($this->consumers[$delivery->queue])($delivery);

        return true;
    }

    public function ack(int $deliveryTag): void
    {
        $this->acked[] = $deliveryTag;
    }

    public function nack(int $deliveryTag, bool $requeue): void
    {
        $this->nacked[] = [$deliveryTag, $requeue];
    }

    public function close(): void
    {
        $this->closed = true;
    }
}
