<?php

declare(strict_types=1);

namespace Firefly\Eda\Kafka;

use Firefly\Eda\Consumer\EventConsumer;
use Firefly\Eda\Consumer\ReceivedEnvelope;
use Firefly\Eda\JsonSerializer;
use RdKafka\KafkaConsumer;
use RdKafka\Message;
use RuntimeException;

/**
 * The Kafka EventConsumer over ext-rdkafka's KafkaConsumer, behind the same skip-if-missing seam as
 * KafkaProducerFactory/KafkaEventPublisher — see KafkaConsumerFactory's docblock for why the `use RdKafka\...;`
 * imports above (added by this repo's Pint preset) never fatal on a no-ext machine: the consumer object is only
 * ever built lazily inside consumer(), which delegates the extension_loaded guard to
 * KafkaConsumerFactory::available().
 *
 * subscribe() hands the concrete topic list straight to rdkafka's KafkaConsumer::subscribe() (topics = concrete
 * patterns already resolved by TopicSubscriptionResolver upstream). poll() calls consume($timeoutMs) and maps
 * $message->err: RD_KAFKA_RESP_ERR_NO_ERROR decodes the payload into a ReceivedEnvelope carrying the rdkafka
 * Message itself as the delivery tag (mirrors PostgresEventConsumer's row-id / RabbitMqEventConsumer's AMQP
 * delivery-tag precedent — broker-native handle, replayed against THIS consumer on ack/nack); anything else
 * (RD_KAFKA_RESP_ERR__PARTITION_EOF / __TIMED_OUT / any other transient code) is "nothing this tick" -> null.
 *
 * ack() is a manual commit($message) — group.id + enable.auto.commit=false (rdkafka's own default) mean nothing is
 * durably consumed until this call. nack(requeue: true) (the ConsumerLoop at-least-once retry default) deliberately
 * does NOT commit, so the uncommitted offset makes Kafka redeliver the same record on the next poll — the
 * broker-native analogue of RabbitMQ's basic_nack(requeue: true). nack(requeue: false) (an exhausted retry) instead
 * re-produces the envelope to a DEAD-LETTER TOPIC ("<topic>.DLT", Kafka has no broker-native DLX like RabbitMQ) via
 * a producer built from the T6 KafkaProducerFactory, THEN commits the original offset — so the exhausted record is
 * never redelivered from its original topic, exactly mirroring RabbitMqEventConsumer's DLX routing outcome with a
 * topic instead of an exchange.
 */
final class KafkaEventConsumer implements EventConsumer
{
    private ?KafkaConsumer $consumer = null;

    public function __construct(
        private readonly KafkaConsumerFactory $factory,
        private readonly KafkaProducerFactory $producerFactory,
        private readonly JsonSerializer $serializer,
        private readonly string $deadLetterSuffix = '.DLT',
    ) {}

    /**
     * @param  list<string>  $destinations
     */
    public function subscribe(array $destinations): void
    {
        $this->consumer()->subscribe($destinations);
    }

    public function start(): void
    {
        $this->consumer();
    }

    public function poll(int $timeoutMs): ?ReceivedEnvelope
    {
        $message = $this->consumer()->consume($timeoutMs);

        return match ($message->err) {
            RD_KAFKA_RESP_ERR_NO_ERROR => new ReceivedEnvelope(
                $this->serializer->deserialize((string) $message->payload),
                $message,
            ),
            default => null, // RD_KAFKA_RESP_ERR__PARTITION_EOF / RD_KAFKA_RESP_ERR__TIMED_OUT / any other transient code.
        };
    }

    public function ack(ReceivedEnvelope $received): void
    {
        $this->consumer()->commit($this->message($received));
    }

    public function nack(ReceivedEnvelope $received, bool $requeue = true): void
    {
        if ($requeue) {
            return; // leave the offset uncommitted -> Kafka redelivers the same record (at-least-once).
        }

        $this->deadLetter($received);
        $this->consumer()->commit($this->message($received));
    }

    public function stop(): void
    {
        $this->consumer?->close();
        $this->consumer = null;
    }

    private function deadLetter(ReceivedEnvelope $received): void
    {
        $message = $this->message($received);
        $producer = $this->producerFactory->producer();
        $topic = $producer->newTopic($message->topic_name.$this->deadLetterSuffix);

        // RD_KAFKA_PARTITION_UA (-1) = librdkafka picks the partition; the DLT record carries no partition key,
        // there is no "correct" partition to preserve once a record has been dead-lettered.
        $topic->produce(RD_KAFKA_PARTITION_UA, 0, $this->serializer->serialize($received->envelope));
        $producer->flush(2000);
    }

    private function message(ReceivedEnvelope $received): Message
    {
        if (! $received->deliveryTag instanceof Message) {
            throw new RuntimeException('ReceivedEnvelope::$deliveryTag must be the rdkafka Message.');
        }

        return $received->deliveryTag;
    }

    private function consumer(): KafkaConsumer
    {
        return $this->consumer ??= $this->factory->consumer();
    }
}
