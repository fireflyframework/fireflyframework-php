<?php

declare(strict_types=1);

namespace Firefly\Eda\Kafka;

use Firefly\Eda\Consumer\ReceivedEnvelope;
use Firefly\Eda\Serializer;
use RdKafka\KafkaConsumer;
use RdKafka\Message;
use RdKafka\Producer;
use RuntimeException;
use Throwable;

/**
 * The REAL KafkaConsumerClient over ext-rdkafka, wrapping the rdkafka KafkaConsumer (built lazily via
 * KafkaConsumerFactory) plus a CACHED DLT Producer (built once via KafkaProducerFactory, reused for every
 * dead-letter). ALL `\RdKafka\*` / `RD_KAFKA_*` touches live inside method bodies that only run after the factories'
 * extension_loaded('rdkafka') guard has already thrown on a no-ext machine — so autoloading this file NEVER fatals
 * without the extension. The `use RdKafka\...;` imports (added by this repo's Pint preset) are compile-time aliases
 * only, never resolved by the engine; the nullable typed props and the `Message` param/return types are only
 * resolved when a real value is assigned/returned, which never happens on a no-ext machine because
 * KafkaConsumerFactory::available()/KafkaProducerFactory::available() throw first. ReflectionFreeEdaKafkaTest
 * requires this very file on a no-ext machine as the direct proof. This is the exact seam-adapter role
 * RabbitMqConsumingChannelAdapter plays for eda-rabbitmq.
 */
final class RdKafkaConsumerClient implements KafkaConsumerClient
{
    private ?KafkaConsumer $consumer = null;

    private ?Producer $producer = null;

    public function __construct(
        private readonly KafkaConsumerFactory $consumerFactory,
        private readonly KafkaProducerFactory $producerFactory,
        private readonly Serializer $serializer,
    ) {}

    /**
     * @param  list<string>  $topics
     */
    public function subscribe(array $topics): void
    {
        $this->consumer()->subscribe($topics);
    }

    /**
     * consume() maps rdkafka's err code: RD_KAFKA_RESP_ERR_NO_ERROR decodes the payload into a ReceivedEnvelope
     * carrying the rdkafka Message itself as the delivery tag (replayed against THIS consumer on commit — the
     * PostgresEventConsumer row-id / RabbitMqEventConsumer delivery-tag precedent). CAVEAT: every other code ->
     * null. That correctly covers the benign "nothing this tick" codes (RD_KAFKA_RESP_ERR__PARTITION_EOF /
     * __TIMED_OUT) but ALSO swallows a genuine broker error code into the same null — ConsumerLoop then just polls
     * again. A documented operability trade-off (matches the pre-seam behaviour), not a behaviour change.
     */
    public function consume(int $timeoutMs): ?ReceivedEnvelope
    {
        $message = $this->consumer()->consume($timeoutMs);

        return match ($message->err) {
            RD_KAFKA_RESP_ERR_NO_ERROR => self::received((string) $message->payload, $message->topic_name, $message, $this->serializer),
            default => null,
        };
    }

    /**
     * The pure half of consume(): the record for one delivered payload. Public and static so it is testable
     * without ext-rdkafka's Message class, which is the only reason consume() itself is not.
     *
     * THE DESERIALISATION HAPPENS HERE, INSIDE A CATCH. It used to happen on the way out of consume() with no
     * catch anywhere between it and ConsumerLoop's `poll()` call, so one malformed body — the most ordinary
     * failure on a topic another language also writes to — killed the worker, and the supervisor restarted it
     * onto the same offset for ever. A body the serializer refuses is now a POISON record carrying the raw
     * bytes and the topic; ConsumerLoop nacks it without requeue, KafkaEventConsumer produces the bytes to
     * `<topic>.DLT` and commits, and the loop is on the next record.
     *
     * THE CATCH IS `Throwable`, ON PURPOSE. The first cut caught SerializationException only, and a body with all
     * six keys but a string payload, an int eventType or a timestamp PHP could not parse went past it as a
     * TypeError or a DateMalformedStringException — the same crash, on the same offset, for the very
     * cross-language bodies this path was written for. The shipped serializer now refuses those as
     * SerializationException too, but the serializer is a PORT: a third-party implementation may throw anything,
     * and nothing it throws changes what the bytes are. Whatever comes out of deserialize(), the record is
     * poison and the worker lives.
     */
    public static function received(string $payload, string $topic, mixed $deliveryTag, Serializer $serializer): ReceivedEnvelope
    {
        try {
            return new ReceivedEnvelope($serializer->deserialize($payload), $deliveryTag, destination: $topic);
        } catch (Throwable $e) {
            return ReceivedEnvelope::poison($payload, $deliveryTag, $e, $topic !== '' ? $topic : null);
        }
    }

    public function commit(mixed $deliveryTag): void
    {
        if (! $deliveryTag instanceof Message) {
            throw new RuntimeException('Kafka commit expects the rdkafka Message as the delivery tag.');
        }

        $this->consumer()->commit($deliveryTag);
    }

    /**
     * Why the record died. PyFly writes the exception's own short class name here (its `type(exc).__name__`), and
     * this side spells the header and the value the same way, because a `<topic>.DLT` both frameworks write to is
     * only readable if one `kcat -f '%h'` explains every record on it.
     */
    public const string REASON_HEADER = 'x-dlt-reason';

    /** The topic the record was CONSUMED from — not the DLT, and not the envelope's declared destination. */
    public const string SOURCE_TOPIC_HEADER = 'x-dlt-source-topic';

    /** The offset the record sat at, which is the only way back to it in the log. */
    public const string SOURCE_OFFSET_HEADER = 'x-dlt-source-offset';

    /**
     * Re-produces the record to $dltTopic WITH the three provenance headers.
     *
     * It used to write the bytes and nothing else. dworkers runs LaraFly and PyFly against shared topics, and PyFly
     * has stamped `x-dlt-reason`, `x-dlt-source-topic` and `x-dlt-source-offset` on every record it dead-letters
     * since `v26.09.06` — so one `<topic>.DLT` held two kinds of record: PyFly's, which says why it died and where
     * it came from, and this one's, which was raw bytes with no provenance at all. Whoever drained that topic could
     * not tell a LaraFly poison record from a replayed payload, and the offset needed to go back and look at the
     * original was simply not there. The header names and the reason's spelling are PyFly's, exactly, because
     * parity across the two ends of a shared topic is the whole point of writing them.
     *
     * producev() rather than produce(): headers are the only thing this method gained, and produce() cannot carry
     * them. It has been in ext-rdkafka since 3.1 (librdkafka 0.11), well below the `librdkafka >= 1.5.3` this
     * package already suggests.
     */
    public function deadLetter(ReceivedEnvelope $received, string $dltTopic, string $reason): void
    {
        $producer = $this->producer();
        $topic = $producer->newTopic($dltTopic);

        // RD_KAFKA_PARTITION_UA (-1) = librdkafka picks the partition; a dead-lettered record carries no partition
        // key, so there is no "correct" partition to preserve.
        $topic->producev(
            RD_KAFKA_PARTITION_UA,
            0,
            self::dltPayload($received, $this->serializer),
            null,
            self::dltHeaders($received, $reason),
        );
        $producer->flush(2000);
    }

    /**
     * The bytes the DLT record carries: a poison record's RAW bytes verbatim — so a fixed producer can replay them
     * byte for byte, which a re-encoded approximation could not — and the re-serialised envelope for anything else.
     *
     * Public and static for the same reason received() is: it is the half of deadLetter() that owes nothing to
     * ext-rdkafka, and it is the half worth asserting on.
     */
    public static function dltPayload(ReceivedEnvelope $received, Serializer $serializer): string
    {
        $envelope = $received->envelope;

        return $envelope === null ? (string) $received->raw : $serializer->serialize($envelope);
    }

    /**
     * The three provenance headers, from the record alone.
     *
     * The offset is read off the delivery tag — the rdkafka Message for the real adapter — through property_exists()
     * rather than an instanceof, so this stays callable, and testable, on a machine with no ext-rdkafka at all (the
     * received() precedent). A header whose value the record cannot answer is LEFT OUT rather than written empty: an
     * `x-dlt-source-offset` of `''` reads as an offset, and sends whoever is draining the topic looking for it.
     *
     * @return array<string, string>
     */
    public static function dltHeaders(ReceivedEnvelope $received, string $reason): array
    {
        $headers = [self::REASON_HEADER => $reason];

        if ($received->destination !== null && $received->destination !== '') {
            $headers[self::SOURCE_TOPIC_HEADER] = $received->destination;
        }

        $tag = $received->deliveryTag;
        if (is_object($tag) && property_exists($tag, 'offset') && is_int($tag->offset)) {
            $headers[self::SOURCE_OFFSET_HEADER] = (string) $tag->offset;
        }

        return $headers;
    }

    /**
     * Let the consumer go — and do NOT call `KafkaConsumer::close()` to do it.
     *
     * ext-rdkafka 6.0.5, kafka_consumer.c:531-542, is the whole body of that method:
     *
     *     rd_kafka_consumer_close(intern->rk);
     *     intern->rk = NULL;
     *
     * There is no `rd_kafka_destroy()`. The free handler at kafka_consumer.c:53-64 destroys the
     * handle only `if (intern->rk)` — which `close()` has just nulled — so `close()` hands the
     * `rd_kafka_t` to nobody. The PHP object is then freed and the client outlives it, with its
     * threads, until the process exits. Measured on PHP 8.5.8 / ext-rdkafka 6.0.5 / librdkafka
     * 2.15.1: four OS threads stranded per closed consumer, still there after five seconds of
     * polling; zero when the reference is simply dropped. It is unconditional, not a race.
     *
     * THAT IS WHY THE NULLING BELOW WAS NOT ENOUGH. Releasing the property is exactly right and was
     * always here — but by the time it ran, `close()` had already detached the handle, so freeing
     * the object freed nothing. The two lines looked like a careful teardown and were a leak.
     *
     * `unsubscribe()` is what leaves the consumer group, and it is safe on a consumer that never
     * subscribed (verified: no throw). librdkafka logs "Destroying cgrp" when the reference is
     * dropped afterwards, so group membership is still surrendered — nothing the old shape
     * achieved is lost.
     *
     * A CONSUMER OF THIS FRAMEWORK FOUND IT THE HARD WAY. In dworkers the same call had been added
     * to three integration tests and a long-running console command, deliberately, to stop a
     * segfault at PHP shutdown after a green suite — and it was the cause of it. Anything that
     * closes a consumer per restart accumulates dead clients and their threads for as long as the
     * process lives, which matters most in exactly the place this class is used: a daemon.
     */
    public function close(): void
    {
        $this->consumer?->unsubscribe();
        $this->consumer = null;
    }

    private function consumer(): KafkaConsumer
    {
        return $this->consumer ??= $this->consumerFactory->consumer();
    }

    private function producer(): Producer
    {
        return $this->producer ??= $this->producerFactory->producer();
    }
}
