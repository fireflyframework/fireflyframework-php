<?php

declare(strict_types=1);

namespace Firefly\Eda\Kafka;

use Firefly\Eda\Consumer\ReceivedEnvelope;
use Firefly\Eda\EventEnvelope;
use Firefly\Eda\JsonSerializer;
use RdKafka\KafkaConsumer;
use RdKafka\Message;
use RdKafka\Producer;
use RuntimeException;

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
        private readonly JsonSerializer $serializer,
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
            RD_KAFKA_RESP_ERR_NO_ERROR => new ReceivedEnvelope(
                $this->serializer->deserialize((string) $message->payload),
                $message,
            ),
            default => null,
        };
    }

    public function commit(mixed $deliveryTag): void
    {
        if (! $deliveryTag instanceof Message) {
            throw new RuntimeException('Kafka commit expects the rdkafka Message as the delivery tag.');
        }

        $this->consumer()->commit($deliveryTag);
    }

    public function deadLetter(EventEnvelope $envelope, string $dltTopic): void
    {
        $producer = $this->producer();
        $topic = $producer->newTopic($dltTopic);

        // RD_KAFKA_PARTITION_UA (-1) = librdkafka picks the partition; a dead-lettered record carries no partition
        // key, so there is no "correct" partition to preserve.
        $topic->produce(RD_KAFKA_PARTITION_UA, 0, $this->serializer->serialize($envelope));
        $producer->flush(2000);
    }

    public function close(): void
    {
        $this->consumer?->close();
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
