<?php

declare(strict_types=1);

namespace Firefly\Eda\Kafka\Tests\Fixtures;

use Firefly\Eda\Consumer\ReceivedEnvelope;
use Firefly\Eda\EventEnvelope;
use Firefly\Eda\Kafka\KafkaConsumerClient;

/**
 * A pure-PHP KafkaConsumerClient fake for KafkaEventConsumerTest: records the (already wildcard-translated) subscribed
 * topics, hands back canned consume() returns, and records every commit/deadLetter/close call — no ext-rdkafka, no
 * socket (the eda-rabbitmq FakeConsumingChannel precedent). This is what lets the ack/nack/DLT correctness core be
 * unit-tested on a machine with no ext-rdkafka.
 */
final class FakeKafkaConsumerClient implements KafkaConsumerClient
{
    /** @var list<string> the exact topic strings the consumer handed to subscribe() (post wildcard translation) */
    public array $subscribedTopics = [];

    /** @var list<ReceivedEnvelope> canned returns for consume(), FIFO */
    public array $cannedMessages = [];

    /** @var list<mixed> every delivery tag commit() was called with, in order */
    public array $committed = [];

    /** @var list<array{0: EventEnvelope, 1: string}> every [envelope, dltTopic] deadLetter() was called with */
    public array $deadLettered = [];

    public int $closeCalls = 0;

    public function subscribe(array $topics): void
    {
        $this->subscribedTopics = $topics;
    }

    public function consume(int $timeoutMs): ?ReceivedEnvelope
    {
        return array_shift($this->cannedMessages);
    }

    public function commit(mixed $deliveryTag): void
    {
        $this->committed[] = $deliveryTag;
    }

    public function deadLetter(EventEnvelope $envelope, string $dltTopic): void
    {
        $this->deadLettered[] = [$envelope, $dltTopic];
    }

    public function close(): void
    {
        $this->closeCalls++;
    }
}
