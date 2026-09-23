<?php

declare(strict_types=1);

namespace Firefly\Eda\Kafka;

/**
 * The minimal, ext-AGNOSTIC seam KafkaEventPublisher programs to — exactly the librdkafka operations producing one
 * record needs, with ZERO `\RdKafka\*` / `RD_KAFKA_*` in any signature. It is KafkaConsumerClient's mirror image on
 * the produce side, and it exists for the same reason that one does: RdKafkaProducerClient wraps the real rdkafka
 * Producer for production — every `\RdKafka\*` touch lives INSIDE its extension_loaded-guarded bodies — while unit
 * tests inject a pure-PHP FakeKafkaProducerClient (no ext, no socket). That is what makes the publisher's
 * correctness core — the envelope it builds, the partition key it derives from the headers, and the `traceparent`
 * the EdaTracing seam stamps on them — assertable on a machine with no ext-rdkafka installed, which is where this
 * package's tests have always had to run.
 *
 * connect() builds the underlying producer eagerly (EventPublisher::start()'s fail-fast); produce() enqueues one
 * record on $topic keyed by $partitionKey; flush() blocks up to $timeoutMs for delivery and is a no-op when nothing
 * has been produced yet, so EventPublisher::stop() never builds a producer only to throw it away.
 */
interface KafkaProducerClient
{
    public function connect(): void;

    public function produce(string $topic, string $body, string $partitionKey): void;

    public function flush(int $timeoutMs): void;
}
