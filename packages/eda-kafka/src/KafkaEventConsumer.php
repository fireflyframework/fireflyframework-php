<?php

declare(strict_types=1);

namespace Firefly\Eda\Kafka;

use Firefly\Eda\Consumer\EventConsumer;
use Firefly\Eda\Consumer\ReceivedEnvelope;

/**
 * The Kafka EventConsumer — pure orchestration over the ext-AGNOSTIC KafkaConsumerClient seam (RdKafkaConsumerClient
 * in production, FakeKafkaConsumerClient in unit tests). This class carries NO `\RdKafka\*` reference at all: every
 * librdkafka call lives inside RdKafkaConsumerClient's extension_loaded-guarded bodies, so the ack/nack/DLT/wildcard
 * correctness core here is unit-testable on a machine with no ext-rdkafka (the eda-rabbitmq RabbitMqEventConsumer /
 * ConsumingChannel precedent).
 *
 * subscribe() TRANSLATES each destination before handing it to the client. librdkafka treats any topic string NOT
 * prefixed with `^` as an EXACT literal topic — so a raw pattern like `order.*` (what TopicSubscriptionResolver emits)
 * would become a literal topic `"order.*"` that never matches, and a wildcard #[EventListener] would silently receive
 * nothing. toTopic() maps a wildcard destination to a `^`-prefixed rdkafka regex (fnmatch->regex: escape regex
 * metachars EXCEPT `*`, `*`->`.*`, prefix `^`; e.g. `order.*` -> `^order\..*`); a wildcard-free destination is a
 * literal topic and passes through UNCHANGED. SubscriberRegistry's fnmatch still does the fine-grained post-receipt
 * filter (TopicSubscriptionResolver's documented contract), so broker-level over-matching (e.g. a bare `*` -> `^.*`)
 * is harmless.
 *
 * ack() is a manual commit — group.id + enable.auto.commit=false + enable.auto.offset.store=false (set explicitly by
 * KafkaConsumerFactory; NOT rdkafka's defaults, which are both `true`) mean nothing is durably consumed until this
 * call. nack(requeue: true) (the ConsumerLoop at-least-once retry default) deliberately does NOT commit: the offset is
 * left uncommitted, so the record is redelivered on the next consumer REBALANCE/RESTART (the fetch position of the
 * SAME running consumer has already advanced past it, so redelivery is not immediate in-session — the honest
 * broker-native analogue of RabbitMQ's basic_nack(requeue: true), which Kafka's log-offset model cannot make
 * immediate without a seek()). nack(requeue: false) (an exhausted retry) instead re-produces the envelope to a
 * DEAD-LETTER TOPIC ("<destination>.DLT", Kafka has no broker-native DLX like RabbitMQ), THEN commits the original
 * offset — so the exhausted record is never redelivered from its original topic, mirroring RabbitMqEventConsumer's DLX
 * routing outcome with a topic instead of an exchange. The DLT topic is derived from the broker-agnostic
 * EventEnvelope::$destination (the topic the publisher targeted), keeping this layer free of `\RdKafka\Message`.
 */
final class KafkaEventConsumer implements EventConsumer
{
    public function __construct(
        private readonly KafkaConsumerClient $client,
        private readonly string $deadLetterSuffix = '.DLT',
    ) {}

    /**
     * @param  list<string>  $destinations
     */
    public function subscribe(array $destinations): void
    {
        $topics = [];
        foreach ($destinations as $destination) {
            $topics[] = $this->toTopic($destination);
        }

        $this->client->subscribe($topics);
    }

    public function start(): void
    {
        // No-op: librdkafka's KafkaConsumer opens no socket on construction (metadata is fetched lazily on the first
        // subscribe()/consume()), so there is no separate connect step to force here — subscribe() has already built
        // the underlying consumer via the client. Kept to honour the EventConsumer lifecycle contract.
    }

    /** poll() returning null also swallows any non-NO_ERROR librdkafka code, including a real broker error (a
     *  documented operability caveat — see RdKafkaConsumerClient::consume()); ConsumerLoop treats null as "no message
     *  this tick" and simply polls again. */
    public function poll(int $timeoutMs): ?ReceivedEnvelope
    {
        return $this->client->consume($timeoutMs);
    }

    public function ack(ReceivedEnvelope $received): void
    {
        $this->client->commit($received->deliveryTag);
    }

    public function nack(ReceivedEnvelope $received, bool $requeue = true): void
    {
        if ($requeue) {
            return; // leave the offset uncommitted -> Kafka redelivers on the next rebalance/restart (at-least-once).
        }

        $this->client->deadLetter($received->envelope, $received->envelope->destination.$this->deadLetterSuffix);
        $this->client->commit($received->deliveryTag);
    }

    public function stop(): void
    {
        $this->client->close();
    }

    private function toTopic(string $destination): string
    {
        if (! str_contains($destination, '*')) {
            return $destination; // an exact literal topic — librdkafka subscribes to it verbatim.
        }

        // fnmatch -> rdkafka regex: preg_quote escapes EVERY metachar (incl. `*` -> `\*`); turn the escaped star
        // back into `.*`, then prefix `^` (librdkafka's regex-subscription marker). e.g. `order.*` -> `^order\..*`.
        return '^'.str_replace('\\*', '.*', preg_quote($destination, '/'));
    }
}
