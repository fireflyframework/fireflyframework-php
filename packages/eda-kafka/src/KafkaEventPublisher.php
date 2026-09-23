<?php

declare(strict_types=1);

namespace Firefly\Eda\Kafka;

use Firefly\Eda\Bus\SubscriberRegistry;
use Firefly\Eda\EventEnvelope;
use Firefly\Eda\EventPublisher;
use Firefly\Eda\JsonSerializer;
use Firefly\Eda\Tracing\EdaTracing;
use Firefly\Eda\Tracing\NoOpEdaTracing;
use RuntimeException;

/**
 * The Kafka EventPublisher. publish() produces a JSON record to topic=$destination with a partition key
 * (partition_key header -> x-correlation-id -> eventType). Every librdkafka call goes through the ext-agnostic
 * KafkaProducerClient seam, whose production implementation (RdKafkaProducerClient) builds the rdkafka objects
 * lazily behind KafkaProducerFactory's extension_loaded guard — so autoloading this class NEVER fatals in a no-ext
 * environment, and the correctness core below is unit-testable there too. $clientOverride exists ONLY for tests (a
 * pure-PHP FakeKafkaProducerClient), exactly as RabbitMqEventPublisher's $channelOverride does; production passes
 * the factory. subscribe() feeds the shared registry; start() builds the producer, stop() flushes and drops it.
 *
 * publish() is routed through the EdaTracing seam, so the record that reaches the topic carries the producer
 * span's `traceparent` — see the method's own docblock.
 */
final class KafkaEventPublisher implements EventPublisher
{
    private ?KafkaProducerClient $client;

    private readonly EdaTracing $tracing;

    public function __construct(
        private readonly ?KafkaProducerFactory $factory,
        private readonly SubscriberRegistry $registry,
        private readonly JsonSerializer $serializer,
        ?EdaTracing $tracing = null,
        ?KafkaProducerClient $clientOverride = null,
    ) {
        $this->client = $clientOverride;
        $this->tracing = $tracing ?? new NoOpEdaTracing;
    }

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
     * Routed through EdaTracing::tracePublish() — the seam InMemoryEventBus and QueueEventBus have always
     * used, and the reason it takes the headers rather than returning them: propagation means WRITING
     * something on the envelope, so the implementation hands the send closure the headers to carry and the
     * adapter builds the record from those. Before this, the three broker adapters built their envelopes
     * themselves and no producer span existed on the wire at all; their CONSUME side was already traced
     * through SubscriberRegistrySink, so a `traceparent` a foreign producer put in the headers was continued
     * while one of our own was never written. A trace that stops at the broker is a trace of half a system.
     *
     * The partition key is computed from the headers the CLOSURE receives, which is what keeps a traceparent
     * from ever becoming one: it is not among the two names partitionKey() reads, so the correlation id still
     * wins and per-key ordering survives being traced. A key that changed on every call would scatter one
     * aggregate's events across every partition — the exact guarantee the correlation-id key exists to give.
     *
     * With tracing off the bound EdaTracing is the NoOp, which calls $send with the caller's headers
     * unchanged, so this path is byte-for-byte what it was.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    public function publish(string $destination, string $eventType, array $payload, array $headers = []): void
    {
        $this->tracing->tracePublish($destination, $eventType, $headers, function (array $headers) use ($destination, $eventType, $payload): void {
            $client = $this->client();
            $body = $this->serializer->serialize(new EventEnvelope($eventType, $destination, $payload, $headers));

            $client->produce($destination, $body, self::partitionKey($eventType, $headers));
            $client->flush(2000);
        });
    }

    public function start(): void
    {
        $this->client()->connect();
    }

    public function stop(): void
    {
        $this->client?->flush(2000);
        $this->client = null;
    }

    private function client(): KafkaProducerClient
    {
        if ($this->client === null) {
            if ($this->factory === null) {
                throw new RuntimeException('KafkaEventPublisher has no producer factory and no client override.');
            }
            $this->client = new RdKafkaProducerClient($this->factory);
        }

        return $this->client;
    }
}
