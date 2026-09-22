<?php

declare(strict_types=1);

use Firefly\Eda\Consumer\ReceivedEnvelope;
use Firefly\Eda\EventEnvelope;
use Firefly\Eda\Exception\SerializationException;
use Firefly\Eda\JsonSerializer;
use Firefly\Eda\Rabbitmq\RabbitMqEventConsumer;
use Firefly\Eda\Rabbitmq\Tests\Fixtures\FakeConsumingChannel;
use Firefly\Eda\Serializer;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * @param  array<string, mixed>  $payload
 */
function queuedMessage(int $deliveryTag, string $eventType = 'order.created', array $payload = ['id' => 1]): AMQPMessage
{
    $body = (new JsonSerializer)->serialize(new EventEnvelope($eventType, 'firefly.events/'.$eventType, $payload));
    $msg = new AMQPMessage($body);
    $msg->setDeliveryTag($deliveryTag);

    return $msg;
}

it('subscribe() declares the exchange, the DLX exchange, and a durable queue carrying the x-dead-letter-exchange arg, then binds each destination', function () {
    $channel = new FakeConsumingChannel;
    $consumer = new RabbitMqEventConsumer(
        connectionFactory: null,
        serializer: new JsonSerializer,
        exchange: 'firefly.events',
        queue: 'firefly.eda',
        deadLetterExchange: 'firefly.events.dlx',
        prefetch: 5,
        channelOverride: $channel,
    );

    $consumer->subscribe(['order.*', 'user.created']);

    expect($channel->declaredExchanges)->toBe(['firefly.events', 'firefly.events.dlx'])
        ->and($channel->declaredQueues)->toHaveKey('firefly.eda')
        ->and($channel->declaredQueues['firefly.eda'])->toBe(['x-dead-letter-exchange' => ['S', 'firefly.events.dlx']])
        ->and($channel->qosPrefetchCount)->toBe(5)
        ->and($channel->bindings)->toBe([
            ['firefly.eda', 'firefly.events', 'order.*'],
            ['firefly.eda', 'firefly.events', 'user.created'],
        ]);
});

it('translates a bare "*" or "**" pattern to the AMQP catch-all "#" routing key', function () {
    $channel = new FakeConsumingChannel;
    $consumer = new RabbitMqEventConsumer(null, new JsonSerializer, channelOverride: $channel);

    $consumer->subscribe(['*', '**']);

    expect($channel->bindings)->toBe([
        ['firefly.eda', 'firefly.events', '#'],
        ['firefly.eda', 'firefly.events', '#'],
    ]);
});

it('poll() returns null on a wait() timeout (no message this tick)', function () {
    $channel = new FakeConsumingChannel;
    $consumer = new RabbitMqEventConsumer(null, new JsonSerializer, channelOverride: $channel);
    $consumer->subscribe(['order.*']);

    expect($consumer->poll(50))->toBeNull();
});

it('poll() converts its millisecond timeout to seconds before calling wait()', function () {
    $channel = new FakeConsumingChannel;
    $consumer = new RabbitMqEventConsumer(null, new JsonSerializer, channelOverride: $channel);
    $consumer->subscribe(['order.*']);

    $consumer->poll(2000);

    expect($channel->waitTimeouts)->toBe([2.0]);
});

it('poll() returns a ReceivedEnvelope carrying the decoded envelope and the AMQP delivery tag', function () {
    $channel = new FakeConsumingChannel;
    $consumer = new RabbitMqEventConsumer(null, new JsonSerializer, channelOverride: $channel);
    $consumer->subscribe(['order.*']);
    $channel->queuedMessages[] = queuedMessage(7, 'order.created', ['id' => 42]);

    $received = $consumer->poll(1000);
    if (! $received instanceof ReceivedEnvelope) {
        throw new RuntimeException('Expected poll() to return a ReceivedEnvelope.');
    }

    expect($received->envelope?->eventType)->toBe('order.created')
        ->and($received->envelope?->payload)->toBe(['id' => 42])
        ->and($received->deliveryTag)->toBe(7);
});

it('registers exactly one basic_consume callback across repeated polls (idempotent registration)', function () {
    $channel = new FakeConsumingChannel;
    $consumer = new RabbitMqEventConsumer(null, new JsonSerializer, channelOverride: $channel);
    $consumer->subscribe(['order.*']);

    $consumer->poll(10);
    $channel->queuedMessages[] = queuedMessage(1);
    $consumer->poll(10);

    expect($channel->consumeRegistrations)->toBe(1);
});

it('ack() acks the delivery tag on the channel', function () {
    $channel = new FakeConsumingChannel;
    $consumer = new RabbitMqEventConsumer(null, new JsonSerializer, channelOverride: $channel);
    $consumer->subscribe(['order.*']);
    $channel->queuedMessages[] = queuedMessage(3);
    $received = $consumer->poll(1000);
    if (! $received instanceof ReceivedEnvelope) {
        throw new RuntimeException('Expected poll() to return a ReceivedEnvelope.');
    }

    $consumer->ack($received);

    expect($channel->acked)->toBe([3]);
});

it('nack(requeue: true) is the at-least-once retry path', function () {
    $channel = new FakeConsumingChannel;
    $consumer = new RabbitMqEventConsumer(null, new JsonSerializer, channelOverride: $channel);
    $consumer->subscribe(['order.*']);
    $channel->queuedMessages[] = queuedMessage(4);
    $received = $consumer->poll(1000);
    if (! $received instanceof ReceivedEnvelope) {
        throw new RuntimeException('Expected poll() to return a ReceivedEnvelope.');
    }

    $consumer->nack($received, true);

    expect($channel->nacked)->toBe([[4, true]]);
});

it('nack(requeue: false) is the broker-native dead-letter path (routed to the DLX by RabbitMQ, not requeued)', function () {
    $channel = new FakeConsumingChannel;
    $consumer = new RabbitMqEventConsumer(null, new JsonSerializer, channelOverride: $channel);
    $consumer->subscribe(['order.*']);
    $channel->queuedMessages[] = queuedMessage(5);
    $received = $consumer->poll(1000);
    if (! $received instanceof ReceivedEnvelope) {
        throw new RuntimeException('Expected poll() to return a ReceivedEnvelope.');
    }

    $consumer->nack($received, false);

    expect($channel->nacked)->toBe([[5, false]]);
});

it('stop() releases the channel and consumer-registration state', function () {
    $channel = new FakeConsumingChannel;
    $consumer = new RabbitMqEventConsumer(null, new JsonSerializer, channelOverride: $channel);
    $consumer->subscribe(['order.*']);
    $channel->queuedMessages[] = queuedMessage(1);
    $consumer->poll(1000);

    $consumer->stop();

    expect(fn () => $consumer->start())->toThrow(RuntimeException::class, 'RabbitMqEventConsumer has no connection factory and no channel override.');
});

it('poll() answers a poison record for an undeserialisable body instead of throwing out of the consume callback', function () {
    $channel = new FakeConsumingChannel;
    $consumer = new RabbitMqEventConsumer(null, new JsonSerializer, channelOverride: $channel);
    $consumer->subscribe(['order.*']);
    $msg = new AMQPMessage('this is not an envelope');
    $msg->setDeliveryTag(9);
    $channel->queuedMessages[] = $msg;

    $received = $consumer->poll(1000);
    if (! $received instanceof ReceivedEnvelope) {
        throw new RuntimeException('Expected poll() to return a ReceivedEnvelope.');
    }

    // ConsumerLoop nacks it without requeue, which the queue's x-dead-letter-exchange routes to the DLX —
    // the broker-native dead letter, with the original body intact.
    expect($received->isPoison())->toBeTrue()
        ->and($received->raw)->toBe('this is not an envelope')
        ->and($received->deliveryTag)->toBe(9)
        ->and($received->failure)->toBeInstanceOf(SerializationException::class);

    $consumer->nack($received, false);
    expect($channel->nacked)->toBe([[9, false]]);
});

/*
 * THE CATCH IS `Throwable`, NOT `SerializationException`: a well-keyed body with a string payload used to leak a
 * TypeError out of the basic_consume callback, past the catch, and kill the worker onto the same message. The
 * serializer is a port and may throw anything; whatever it throws, the record is poison and the DLX gets it.
 */
it('poll() answers a poison record for ANY throw from the serializer, not only a SerializationException', function () {
    $throwing = new class implements Serializer
    {
        public function serialize(EventEnvelope $envelope): string
        {
            return '';
        }

        public function deserialize(string $raw): EventEnvelope
        {
            throw new TypeError('EventEnvelope::__construct(): Argument #3 ($payload) must be of type array, string given');
        }
    };
    $channel = new FakeConsumingChannel;
    $consumer = new RabbitMqEventConsumer(null, $throwing, channelOverride: $channel);
    $consumer->subscribe(['order.*']);
    $msg = new AMQPMessage('{"payload":"str"}');
    $msg->setDeliveryTag(11);
    $channel->queuedMessages[] = $msg;

    $received = $consumer->poll(1000);
    if (! $received instanceof ReceivedEnvelope) {
        throw new RuntimeException('Expected poll() to return a ReceivedEnvelope.');
    }

    expect($received->isPoison())->toBeTrue()
        ->and($received->raw)->toBe('{"payload":"str"}')
        ->and($received->deliveryTag)->toBe(11)
        ->and($received->failure)->toBeInstanceOf(TypeError::class);

    $consumer->nack($received, false);
    expect($channel->nacked)->toBe([[11, false]]);
});

it('poll() answers a poison record for a well-keyed body the shipped serializer refuses on member type', function (string $raw) {
    $channel = new FakeConsumingChannel;
    $consumer = new RabbitMqEventConsumer(null, new JsonSerializer, channelOverride: $channel);
    $consumer->subscribe(['order.*']);
    $msg = new AMQPMessage($raw);
    $msg->setDeliveryTag(12);
    $channel->queuedMessages[] = $msg;

    $received = $consumer->poll(1000);

    expect($received?->isPoison())->toBeTrue()
        ->and($received?->raw)->toBe($raw)
        ->and($received?->failure)->toBeInstanceOf(SerializationException::class);
})->with([
    'payload is a string' => '{"eventType":"x","destination":"t","payload":"str","headers":{},"eventId":"1","timestamp":"2026-01-01T00:00:00Z"}',
    'bad timestamp' => '{"eventType":"x","destination":"t","payload":{},"headers":{},"eventId":"1","timestamp":"garbage"}',
    'eventType is int' => '{"eventType":5,"destination":"t","payload":{},"headers":{},"eventId":"1","timestamp":"2026-01-01T00:00:00Z"}',
]);
