<?php

declare(strict_types=1);

namespace Firefly\Eda\Rabbitmq\Tests\Fixtures;

use Firefly\Eda\Rabbitmq\ConsumingChannel;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * A duck-typed ConsumingChannel fake for RabbitMqEventConsumerTest: buffers AMQPMessages for wait() to hand to the
 * registered basic_consume callback and records every declare/bind/qos/ack/nack call — no real socket, no real
 * AMQPStreamConnection (the eda package's ScriptedEventConsumer / RabbitMqEventPublisherTest's PublishingChannel
 * double precedent).
 */
final class FakeConsumingChannel implements ConsumingChannel
{
    /** @var list<string> */
    public array $declaredExchanges = [];

    /** @var array<string, array<string, array{0: string, 1: mixed}>> */
    public array $declaredQueues = [];

    /** @var list<array{0: string, 1: string, 2: string}> */
    public array $bindings = [];

    public ?int $qosPrefetchCount = null;

    public int $consumeRegistrations = 0;

    /** @var ?callable(AMQPMessage): void */
    public $consumeCallback = null;

    /** @var list<AMQPMessage> */
    public array $queuedMessages = [];

    /** @var list<int> */
    public array $acked = [];

    /** @var list<array{0: int, 1: bool}> */
    public array $nacked = [];

    public function exchange_declare(string $exchange, string $type, bool $passive, bool $durable, bool $autoDelete): void
    {
        $this->declaredExchanges[] = $exchange;
    }

    public function queue_declare(string $queue, bool $durable, array $arguments): void
    {
        $this->declaredQueues[$queue] = $arguments;
    }

    public function queue_bind(string $queue, string $exchange, string $routingKey): void
    {
        $this->bindings[] = [$queue, $exchange, $routingKey];
    }

    public function basic_qos(int $prefetchSize, int $prefetchCount, bool $global): void
    {
        $this->qosPrefetchCount = $prefetchCount;
    }

    public function basic_consume(string $queue, callable $callback): void
    {
        $this->consumeRegistrations++;
        $this->consumeCallback = $callback;
    }

    public function wait(float $timeoutSeconds): void
    {
        if ($this->queuedMessages === []) {
            throw new AMQPTimeoutException('no message within timeout');
        }

        $callback = $this->consumeCallback;
        if ($callback === null) {
            throw new AMQPTimeoutException('no consumer registered');
        }

        $callback(array_shift($this->queuedMessages));
    }

    public function basic_ack(int $deliveryTag): void
    {
        $this->acked[] = $deliveryTag;
    }

    public function basic_nack(int $deliveryTag, bool $requeue): void
    {
        $this->nacked[] = [$deliveryTag, $requeue];
    }
}
