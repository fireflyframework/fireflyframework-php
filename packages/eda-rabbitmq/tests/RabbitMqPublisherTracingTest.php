<?php

declare(strict_types=1);

use Firefly\Eda\Bus\SubscriberRegistry;
use Firefly\Eda\EventEnvelope;
use Firefly\Eda\JsonSerializer;
use Firefly\Eda\Rabbitmq\RabbitMqEventPublisher;
use Firefly\Eda\Rabbitmq\Tests\Fixtures\CapturingPublishingChannel;
use Firefly\Eda\Tracing\EdaTracing;

/**
 * The producer side of the trace, on the RabbitMQ wire.
 *
 * The consume side has always been traced (SubscriberRegistrySink runs every delivered envelope through
 * EdaTracing::traceConsume()), so a `traceparent` a FOREIGN producer wrote was continued while one of our own was
 * never written at all: the trace stopped dead at the broker. publish() now routes through
 * EdaTracing::tracePublish(), the same seam InMemoryEventBus and QueueEventBus have always used, and the envelope
 * is built from the headers that seam hands the send closure.
 *
 * The double below stamps a FIXED traceparent, so what these tests assert is the ROUTING — that the envelope on
 * the wire is built from the tracing implementation's headers rather than the caller's — and not the behaviour of
 * a real tracer, which firefly/observability owns and tests.
 */
function rabbitMqStampingTracing(): EdaTracing
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
    $channel = new CapturingPublishingChannel;

    $publisher = new RabbitMqEventPublisher(
        connectionFactory: null,
        registry: new SubscriberRegistry,
        serializer: new JsonSerializer,
        exchange: 'firefly.events',
        channelOverride: $channel,
        tracing: rabbitMqStampingTracing(),
    );

    $publisher->publish('firefly.events/orders.placed', 'orders.placed', ['id' => 1], ['x-correlation-id' => 'c-1']);

    // The routing is unchanged — the whole body moved inside the closure, it did not move elsewhere.
    expect($channel->published[0][1])->toBe('firefly.events')
        ->and($channel->published[0][2])->toBe('orders.placed');

    $envelope = $channel->decoded();

    expect($envelope['headers'])->toBe([
        'x-correlation-id' => 'c-1',
        'traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
        'tracestate' => 'firefly=1',
    ]);
});

it('publishes exactly as before when no tracing is given', function () {
    $channel = new CapturingPublishingChannel;

    $publisher = new RabbitMqEventPublisher(null, new SubscriberRegistry, new JsonSerializer, 'firefly.events', $channel);
    $publisher->publish('firefly.events/orders.placed', 'orders.placed', ['id' => 1], ['x-correlation-id' => 'c-1']);

    // NoOpEdaTracing calls $send with the caller's headers untouched, so this path is byte-for-byte what it was.
    expect($channel->decoded()['headers'])->toBe(['x-correlation-id' => 'c-1'])
        ->and($channel->declared[0])->toBe(['firefly.events', 'topic', false, true, false]);
});
