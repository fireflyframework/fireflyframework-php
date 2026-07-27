<?php

declare(strict_types=1);

namespace Firefly\Eda\Kafka;

use Firefly\Eda\Consumer\ReceivedEnvelope;
use Firefly\Eda\EventEnvelope;

/**
 * The minimal, ext-AGNOSTIC seam KafkaEventConsumer programs to — exactly the librdkafka operations the consumer
 * orchestration needs, with ZERO `\RdKafka\*` / `RD_KAFKA_*` in any signature (mirrors eda-rabbitmq's ConsumingChannel
 * precedent). RdKafkaConsumerClient wraps the real rdkafka KafkaConsumer + the DLT Producer for production — every
 * `\RdKafka\*` touch lives INSIDE its extension_loaded-guarded bodies — while unit tests inject a pure-PHP
 * FakeKafkaConsumerClient (no ext, no socket). This is what makes the ack/nack/DLT correctness core unit-testable on a
 * machine with no ext-rdkafka installed.
 *
 * consume() returns a mapped ?ReceivedEnvelope (null = "nothing this tick"): the RD_KAFKA_RESP_ERR_* `match` that
 * decides message-vs-nothing lives inside RdKafkaConsumerClient because those constants are ext-only. commit()'s
 * $deliveryTag is the broker-native handle carried on the ReceivedEnvelope (the rdkafka Message for the real adapter);
 * deadLetter() re-produces $envelope to the already-computed $dltTopic string.
 */
interface KafkaConsumerClient
{
    /** @param  list<string>  $topics rdkafka subscription strings — literal topics or `^`-prefixed regexes. */
    public function subscribe(array $topics): void;

    /** Block up to $timeoutMs for one record; null on timeout / any non-NO_ERROR code (see the impl's caveat). */
    public function consume(int $timeoutMs): ?ReceivedEnvelope;

    public function commit(mixed $deliveryTag): void;

    public function deadLetter(EventEnvelope $envelope, string $dltTopic): void;

    public function close(): void;
}
