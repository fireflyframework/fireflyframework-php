<?php

declare(strict_types=1);

use Firefly\Eda\Bus\SubscriberRegistry;
use Firefly\Eda\EventEnvelope;
use Firefly\Eda\JsonSerializer;
use Firefly\Eda\Kafka\KafkaEventPublisher;
use Firefly\Eda\Kafka\Tests\Fixtures\FakeKafkaProducerClient;
use Firefly\Eda\Tracing\EdaTracing;

/**
 * The producer side of the trace, on the Kafka wire — the RabbitMqPublisherTracingTest sibling. publish() routes
 * through EdaTracing::tracePublish(), the seam InMemoryEventBus and QueueEventBus have always used, and both the
 * envelope AND the partition key are derived from the headers that seam hands the send closure.
 *
 * The partition key matters here in a way it does not for the other two adapters: it is computed from the
 * CLOSURE's headers, so this file pins that a stamped traceparent never becomes a partition key (it is not one of
 * the two names partitionKey() reads) while the correlation id still is — otherwise every publish would land on a
 * different partition and per-key ordering, the reason the correlation id is the key at all, would be gone.
 */
function kafkaStampingTracing(): EdaTracing
{
    return new class implements EdaTracing
    {
        public function tracePublish(string $destination, string $eventType, array $headers, callable $send): void
        {
            $send([...$headers, 'traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01', 'tracestate' => 'firefly=1']);
        }

        public function traceConsume(EventEnvelope $envelope, callable $deliver): void
        {
            $deliver($envelope);
        }
    };
}

it('carries the traceparent onto the wire', function () {
    $client = new FakeKafkaProducerClient;

    $publisher = new KafkaEventPublisher(
        factory: null,
        registry: new SubscriberRegistry,
        serializer: new JsonSerializer,
        tracing: kafkaStampingTracing(),
        clientOverride: $client,
    );

    $publisher->publish('orders', 'orders.placed', ['id' => 1], ['x-correlation-id' => 'c-1']);

    expect($client->decoded()['headers'])->toBe([
        'x-correlation-id' => 'c-1',
        'traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
        'tracestate' => 'firefly=1',
    ]);

    // The topic is the destination, the flush is still synchronous, and the partition key is still the
    // correlation id — a traceparent is unique per call and would shatter per-key ordering if it won.
    expect($client->produced[0][0])->toBe('orders')
        ->and($client->produced[0][2])->toBe('c-1')
        ->and($client->flushes)->toBe([2000]);
});

it('produces exactly as before when no tracing is given', function () {
    $client = new FakeKafkaProducerClient;

    $publisher = new KafkaEventPublisher(null, new SubscriberRegistry, new JsonSerializer, null, $client);
    $publisher->publish('orders', 'orders.placed', ['id' => 1], ['x-correlation-id' => 'c-1']);

    expect($client->decoded()['headers'])->toBe(['x-correlation-id' => 'c-1'])
        ->and($client->produced[0][2])->toBe('c-1');
});
