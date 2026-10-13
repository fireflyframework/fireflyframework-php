<?php

declare(strict_types=1);

namespace Firefly\Eda\Kafka\Messaging;

/**
 * The raw-record seam KafkaMessageBroker programs to — the KafkaConsumerClient/KafkaProducerClient precedent, for
 * bytes instead of envelopes. No `\RdKafka\*` and no `RD_KAFKA_*` in any signature: RdKafkaRecordClient wraps the
 * real consumer and producer, a pure-PHP fake drives the unit tests.
 */
interface KafkaRecordClient
{
    /** @param  list<string>  $topics */
    public function subscribe(array $topics): void;

    /** Block up to $timeoutMs for one record; null on timeout or any non-NO_ERROR code. */
    public function consume(int $timeoutMs): ?KafkaRecord;

    public function commit(KafkaRecord $record): void;

    /** @param  array<string, string>  $headers */
    public function produce(string $topic, string $value, ?string $key, array $headers): void;

    public function flush(int $timeoutMs): void;

    public function close(): void;
}
