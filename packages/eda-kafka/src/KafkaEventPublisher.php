<?php

declare(strict_types=1);

namespace Firefly\Eda\Kafka;

use Firefly\Eda\Bus\SubscriberRegistry;
use Firefly\Eda\EventEnvelope;
use Firefly\Eda\EventPublisher;
use Firefly\Eda\JsonSerializer;
use RdKafka\Producer;

/**
 * The Kafka EventPublisher over ext-rdkafka. publish() produces a JSON record to topic=$destination with a partition
 * key (partition_key header -> x-correlation-id -> eventType). The rdkafka objects are created lazily via the factory
 * (guarded by extension_loaded), so autoloading this class NEVER fatals in a no-ext environment — see
 * KafkaProducerFactory's docblock for why the `use RdKafka\Producer;` import above (added by this repo's Pint
 * preset) does not change that. subscribe() feeds the shared registry; start() builds the producer, stop()
 * flushes it.
 */
final class KafkaEventPublisher implements EventPublisher
{
    private ?Producer $producer = null;

    public function __construct(
        private readonly KafkaProducerFactory $factory,
        private readonly SubscriberRegistry $registry,
        private readonly JsonSerializer $serializer,
    ) {}

    /** @param array<string,string> $headers */
    public static function partitionKey(string $eventType, array $headers): string
    {
        return $headers['partition_key'] ?? $headers['x-correlation-id'] ?? $eventType;
    }

    public function subscribe(string $eventTypePattern, callable $handler): void
    {
        $this->registry->subscribe($eventTypePattern, $handler);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    public function publish(string $destination, string $eventType, array $payload, array $headers = []): void
    {
        $producer = $this->producer();
        $topic = $producer->newTopic($destination);
        $body = $this->serializer->serialize(new EventEnvelope($eventType, $destination, $payload, $headers));

        // RD_KAFKA_PARTITION_UA (-1) = librdkafka picks the partition from the key hash.
        $topic->produce(RD_KAFKA_PARTITION_UA, 0, $body, self::partitionKey($eventType, $headers));
        $producer->flush(2000);
    }

    public function start(): void
    {
        $this->producer();
    }

    public function stop(): void
    {
        $this->producer?->flush(2000);
        $this->producer = null;
    }

    private function producer(): Producer
    {
        return $this->producer ??= $this->factory->producer();
    }
}
