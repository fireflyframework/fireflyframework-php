<?php

declare(strict_types=1);

namespace Firefly\Eda\Kafka\Messaging;

/**
 * One consumed Kafka record, ext-agnostic: the topic, the raw value and key, string headers, where it sat
 * (partition/offset — the offset is what a dead letter cites), and the broker-native handle the commit replays
 * (the rdkafka Message for the real client, null in tests).
 */
final readonly class KafkaRecord
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public string $topic,
        public string $value,
        public ?string $key,
        public array $headers,
        public int $partition,
        public int $offset,
        public mixed $handle,
    ) {}
}
