<?php

declare(strict_types=1);

namespace Firefly\Eda\Kafka;

use RdKafka\Producer;

/**
 * The production KafkaProducerClient: the real rdkafka Producer, built LAZILY through KafkaProducerFactory (which
 * is the extension_loaded guard) and reused for every record — the RdKafkaConsumerClient precedent on the produce
 * side. Every `\RdKafka\*` touch and every `RD_KAFKA_*` constant in this package's publish path lives in this file,
 * inside a body that cannot run before KafkaProducerFactory::producer() has confirmed the extension is present.
 *
 * The `use RdKafka\Producer;` import above is added by this repo's own Pint preset (`fully_qualified_strict_types`)
 * and is SAFE with the extension absent for the reason KafkaProducerFactory's docblock sets out at length: a PHP
 * `use` statement is a compile-time alias only and never triggers class resolution by itself, and the property and
 * return types that name it are only resolved at the moment a real value is assigned or returned — which
 * KafkaProducerFactory::producer() always throws before. ReflectionFreeEdaKafkaTest requires every file in this
 * directory on a no-ext machine to keep that a proven property rather than a claimed one.
 */
final class RdKafkaProducerClient implements KafkaProducerClient
{
    private ?Producer $producer = null;

    public function __construct(private readonly KafkaProducerFactory $factory) {}

    public function connect(): void
    {
        $this->producer();
    }

    public function produce(string $topic, string $body, string $partitionKey): void
    {
        // RD_KAFKA_PARTITION_UA (-1) = librdkafka picks the partition from the key hash.
        $this->producer()->newTopic($topic)->produce(RD_KAFKA_PARTITION_UA, 0, $body, $partitionKey);
    }

    /** No producer yet means nothing outstanding to flush — never build one just to drain it (stop()'s path). */
    public function flush(int $timeoutMs): void
    {
        $this->producer?->flush($timeoutMs);
    }

    private function producer(): Producer
    {
        return $this->producer ??= $this->factory->producer();
    }
}
