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
 * close() unsubscribes and drops the consumer instead of calling KafkaConsumer::close(), for the thread leak
 * RdKafkaConsumerClient::close() documents (ext-rdkafka 6.0.5 never destroys a closed handle).
 */
final class RdKafkaRecordClient implements KafkaRecordClient
{
    private ?KafkaConsumer $consumer = null;

    private ?Producer $producer = null;

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
            return;
        }

        $result = $this->producer->flush($timeoutMs);
        if ($result !== RD_KAFKA_RESP_ERR_NO_ERROR) {
            throw new MessagingException("Kafka did not acknowledge the produced records within {$timeoutMs}ms (librdkafka error {$result}).", 'MESSAGING_KAFKA_FLUSH_TIMEOUT');
        }
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
        return $this->producer ??= $this->producers->producer();
    }
}
