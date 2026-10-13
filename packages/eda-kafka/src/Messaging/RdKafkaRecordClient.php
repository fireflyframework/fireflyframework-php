<?php

declare(strict_types=1);

namespace Firefly\Eda\Kafka\Messaging;

use Firefly\Eda\Kafka\KafkaConsumerFactory;
use Firefly\Eda\Kafka\KafkaProducerFactory;
use Firefly\Messaging\Exception\MessagingException;
use RdKafka\KafkaConsumer;
use RdKafka\Message;
use RdKafka\Producer;
use RuntimeException;

/**
 * The production KafkaRecordClient over ext-rdkafka, built on THIS package's factories so the messaging transport
 * and the event transport configure librdkafka identically (manual commits only: enable.auto.commit and
 * enable.auto.offset.store are off in KafkaConsumerFactory). The consumer and the producer are both built lazily,
 * after the factories' extension_loaded() guard; every `RD_KAFKA_*` constant sits in a body that runs only after it,
 * so this file autoloads on a machine without the extension (ReflectionFreeEdaKafkaTest requires it to).
 *
 * DELIVERY IS CONFIRMED PER MESSAGE, not per flush. Producer::flush() answers NO_ERROR once librdkafka's out-queue
 * is empty, and a message that failed delivery permanently (TOPIC_AUTHORIZATION_FAILED, over the topic's
 * max.message.bytes, timed out) leaves that queue too — its failure is reported only to the delivery-report
 * callback. So the producer is built with one (KafkaProducerFactory::producer($deliveryReport)), every failed
 * report is collected by delivered(), and flush() throws MessagingException when any arrived since the last flush:
 * KafkaMessageBroker then leaves the source offset uncommitted, and publish() fails instead of answering as if the
 * message were on the broker.
 *
 * close() unsubscribes and drops the consumer instead of calling KafkaConsumer::close(), for the thread leak
 * RdKafkaConsumerClient::close() documents (ext-rdkafka 6.0.5 never destroys a closed handle).
 */
final class RdKafkaRecordClient implements KafkaRecordClient
{
    private ?KafkaConsumer $consumer = null;

    private ?Producer $producer = null;

    /** @var list<string> the delivery failures reported since the last flush() */
    private array $undelivered = [];

    public function __construct(
        private readonly KafkaConsumerFactory $consumers,
        private readonly KafkaProducerFactory $producers,
    ) {}

    public function subscribe(array $topics): void
    {
        $this->consumer()->subscribe($topics);
    }

    public function consume(int $timeoutMs): ?KafkaRecord
    {
        $message = $this->consumer()->consume($timeoutMs);
        if ($message->err !== RD_KAFKA_RESP_ERR_NO_ERROR) {
            return null;
        }

        return self::record($message->topic_name, $message->payload, $message->key, $message->headers, $message->partition, $message->offset, $message);
    }

    /**
     * The pure half of consume(), public and static so it is testable without the extension's Message class.
     *
     * @param  array<array-key, mixed>|null  $headers
     */
    public static function record(string $topic, ?string $payload, ?string $key, ?array $headers, int $partition, int $offset, mixed $handle): KafkaRecord
    {
        $strings = [];
        foreach ($headers ?? [] as $name => $value) {
            if (is_scalar($value)) {
                $strings[(string) $name] = (string) $value;
            }
        }

        return new KafkaRecord($topic, $payload ?? '', $key, $strings, $partition, $offset, $handle);
    }

    public function commit(KafkaRecord $record): void
    {
        if (! $record->handle instanceof Message) {
            throw new RuntimeException('A Kafka commit replays the rdkafka Message the record was consumed as.');
        }

        $this->consumer()->commit($record->handle);
    }

    public function produce(string $topic, string $value, ?string $key, array $headers): void
    {
        // RD_KAFKA_PARTITION_UA (-1): librdkafka picks the partition from the key hash.
        $this->producer()->newTopic($topic)->producev(RD_KAFKA_PARTITION_UA, 0, $value, $key, $headers === [] ? null : $headers);
    }

    public function flush(int $timeoutMs): void
    {
        if ($this->producer === null) {
            $this->throwIfUndelivered();

            return;
        }

        $result = $this->producer->flush($timeoutMs);
        if ($result !== RD_KAFKA_RESP_ERR_NO_ERROR) {
            throw new MessagingException("Kafka did not acknowledge the produced records within {$timeoutMs}ms (librdkafka error {$result}).", 'MESSAGING_KAFKA_FLUSH_TIMEOUT');
        }

        $this->throwIfUndelivered();
    }

    /**
     * The pure half of the delivery-report callback, public so it is testable without a broker: an error code other
     * than 0 (RD_KAFKA_RESP_ERR_NO_ERROR) is a message librdkafka gave up on, held until the next flush() throws it.
     */
    public function delivered(int $error, string $reason, ?string $topic): void
    {
        if ($error !== 0) {
            $this->undelivered[] = '['.($topic ?? '?')."] {$reason} (librdkafka error {$error})";
        }
    }

    private function throwIfUndelivered(): void
    {
        if ($this->undelivered === []) {
            return;
        }

        $failures = implode('; ', $this->undelivered);
        $this->undelivered = [];

        throw new MessagingException("Kafka rejected produced record(s): {$failures}.", 'MESSAGING_KAFKA_DELIVERY_FAILED');
    }

    public function close(): void
    {
        $this->consumer?->unsubscribe();
        $this->consumer = null;
    }

    private function consumer(): KafkaConsumer
    {
        return $this->consumer ??= $this->consumers->consumer();
    }

    private function producer(): Producer
    {
        return $this->producer ??= $this->producers->producer(function (Producer $producer, Message $message): void {
            $this->delivered($message->err, $message->errstr(), $message->topic_name);
        });
    }
}
