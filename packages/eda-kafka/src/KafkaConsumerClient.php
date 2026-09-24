<?php

declare(strict_types=1);

namespace Firefly\Eda\Kafka;

use Firefly\Eda\Consumer\ReceivedEnvelope;

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
 * deadLetter() re-produces one record to the already-computed $dltTopic string.
 *
 * deadLetter() TAKES THE WHOLE RECORD, and it used to take the pieces — an EventEnvelope for an exhausted retry, and
 * a second method taking raw bytes for a poison record. Both wrote a record with NO headers at all, which is what a
 * `<topic>.DLT` cannot afford: whoever drains that topic has to be able to tell a dead-lettered record from a replayed
 * payload, and to find the offset it came from. The record carries everything that answer needs — the bytes or the
 * envelope, the topic it was read from, and the broker handle the offset hangs off — so the port hands it over whole
 * rather than making each adapter re-derive provenance it was never given.
 */
interface KafkaConsumerClient
{
    /** @param  list<string>  $topics rdkafka subscription strings — literal topics or `^`-prefixed regexes. */
    public function subscribe(array $topics): void;

    /** Block up to $timeoutMs for one record; null on timeout / any non-NO_ERROR code (see the impl's caveat). */
    public function consume(int $timeoutMs): ?ReceivedEnvelope;

    public function commit(mixed $deliveryTag): void;

    /**
     * Re-produce one record to $dltTopic with $reason recorded on it.
     *
     * A POISON record (ReceivedEnvelope::poison()) is re-produced from its RAW bytes, verbatim, so a fixed producer
     * can replay them byte for byte; any other record is re-encoded from its envelope.
     */
    public function deadLetter(ReceivedEnvelope $received, string $dltTopic, string $reason): void;

    public function close(): void;
}
