<?php

declare(strict_types=1);

use Firefly\Eda\Consumer\ReceivedEnvelope;
use Firefly\Eda\EventEnvelope;
use Firefly\Eda\Exception\SerializationException;
use Firefly\Eda\JsonSerializer;
use Firefly\Eda\Kafka\KafkaEventConsumer;
use Firefly\Eda\Kafka\RdKafkaConsumerClient;
use Firefly\Eda\Kafka\Tests\Fixtures\FakeKafkaConsumerClient;
use Firefly\Eda\Serializer;

/**
 * Socket-free unit tests for the KafkaEventConsumer ack/nack/DLT/wildcard correctness core, over the pure-PHP
 * KafkaConsumerClient seam (FakeKafkaConsumerClient) — no ext-rdkafka, no broker (the eda-rabbitmq
 * RabbitMqEventConsumerTest precedent). The real end-to-end path stays proven by the ext+Docker-gated
 * KafkaRoundTripTest.
 */
function receivedFor(string $eventType = 'order.created', string $destination = 'order.events', mixed $deliveryTag = 'tag'): ReceivedEnvelope
{
    return new ReceivedEnvelope(new EventEnvelope($eventType, $destination, ['id' => 1]), $deliveryTag);
}

it('(C1) subscribe() translates wildcard patterns to ^-prefixed rdkafka regexes and passes literal topics through unchanged', function () {
    $client = new FakeKafkaConsumerClient;
    $consumer = new KafkaEventConsumer($client);

    $consumer->subscribe(['order.*', 'user.created']);

    // fnmatch->rdkafka regex: escape regex metachars EXCEPT '*', '*'->'.*', prefix '^'; a wildcard-free
    // destination is an EXACT literal topic and passes through untouched. Without translation, librdkafka would
    // treat 'order.*' as a LITERAL topic name that never matches -> the wildcard listener silently gets nothing.
    expect($client->subscribedTopics)->toBe(['^order\..*', 'user.created']);
});

it('ack() commits the delivery tag on the client', function () {
    $client = new FakeKafkaConsumerClient;
    $consumer = new KafkaEventConsumer($client);

    $consumer->ack(receivedFor(deliveryTag: 'tag-7'));

    expect($client->committed)->toBe(['tag-7']);
});

it('nack(requeue: true) is the at-least-once retry path — it does NOT commit (offset left uncommitted for redelivery)', function () {
    $client = new FakeKafkaConsumerClient;
    $consumer = new KafkaEventConsumer($client);

    $consumer->nack(receivedFor(deliveryTag: 'tag-9'), true);

    expect($client->committed)->toBe([])
        ->and($client->deadLettered)->toBe([]);
});

it('nack(requeue: false) dead-letters the envelope to "<destination>.DLT" THEN commits the original offset', function () {
    $client = new FakeKafkaConsumerClient;
    $consumer = new KafkaEventConsumer($client);
    $envelope = new EventEnvelope('order.created', 'order.events', ['id' => 42]);

    $consumer->nack(new ReceivedEnvelope($envelope, 'tag-5'), false);

    expect($client->deadLettered)->toBe([[$envelope, 'order.events.DLT']])
        ->and($client->committed)->toBe(['tag-5']);
});

it('dead-letters a POISON record\'s raw bytes to "<topic>.DLT" then commits, so the loop never re-reads it', function () {
    $client = new FakeKafkaConsumerClient;
    $consumer = new KafkaEventConsumer($client);
    $poison = ReceivedEnvelope::poison('{"eventId": 1, "no": "shape"}', 'tag-3', new RuntimeException('shape'), 'order.events');

    $consumer->nack($poison, false);

    // The RAW bytes, not a re-encoded approximation, so a fixed producer can be replayed byte for byte.
    expect($client->deadLetteredRaw)->toBe([['{"eventId": 1, "no": "shape"}', 'order.events.DLT']])
        ->and($client->deadLettered)->toBe([])
        ->and($client->committed)->toBe(['tag-3']);
});

/*
 * THE DESERIALISATION IS INSIDE THE RECORD, NOT AROUND THE POLL. RdKafkaConsumerClient::consume() deserialised the
 * payload on the way out, outside every try/catch in the process, so one malformed body killed the worker.
 * received() is the pure half of consume() — testable without ext-rdkafka's Message — and it answers a poison
 * record instead of throwing.
 */
it('turns an undeserialisable Kafka payload into a poison record carrying the raw bytes and the topic', function () {
    $received = RdKafkaConsumerClient::received('not json at all', 'order.events', 'the-rdkafka-message', new JsonSerializer);

    expect($received->isPoison())->toBeTrue()
        ->and($received->envelope)->toBeNull()
        ->and($received->raw)->toBe('not json at all')
        ->and($received->destination)->toBe('order.events')
        ->and($received->deliveryTag)->toBe('the-rdkafka-message')
        ->and($received->failure)->toBeInstanceOf(SerializationException::class);
});

it('turns a well-formed Kafka payload into an ordinary record', function () {
    $envelope = new EventEnvelope('order.created', 'order.events', ['id' => 1]);
    $received = RdKafkaConsumerClient::received((new JsonSerializer)->serialize($envelope), 'order.events', 'tag', new JsonSerializer);

    expect($received->isPoison())->toBeFalse()
        ->and($received->envelope?->eventType)->toBe('order.created')
        ->and($received->deliveryTag)->toBe('tag');
});

/*
 * THE CATCH IS `Throwable`, NOT `SerializationException`. The serializer is a port; a third-party implementation
 * may throw anything, and even the shipped one used to leak a TypeError for a well-keyed body with a string
 * payload. Whatever the decode throws, the bytes are the bytes: the record is poison, the failure is kept, and
 * the worker lives.
 */
it('turns ANY throw from the serializer into a poison record, not only a SerializationException', function () {
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

    $received = RdKafkaConsumerClient::received('{"payload":"str"}', 'order.events', 'tag-9', $throwing);

    expect($received->isPoison())->toBeTrue()
        ->and($received->raw)->toBe('{"payload":"str"}')
        ->and($received->deliveryTag)->toBe('tag-9')
        ->and($received->failure)->toBeInstanceOf(TypeError::class);
});

it('turns every malformed body the skeptic probe used into a poison record with the shipped serializer', function (string $raw) {
    $received = RdKafkaConsumerClient::received($raw, 'order.events', 'tag', new JsonSerializer);

    expect($received->isPoison())->toBeTrue()
        ->and($received->raw)->toBe($raw)
        ->and($received->failure)->toBeInstanceOf(SerializationException::class);
})->with([
    'not json' => '{oops',
    'wrong shape' => '{"a":1}',
    'payload is a string' => '{"eventType":"x","destination":"t","payload":"str","headers":{},"eventId":"1","timestamp":"2026-01-01T00:00:00Z"}',
    'bad timestamp' => '{"eventType":"x","destination":"t","payload":{},"headers":{},"eventId":"1","timestamp":"garbage"}',
    'eventType is int' => '{"eventType":5,"destination":"t","payload":{},"headers":{},"eventId":"1","timestamp":"2026-01-01T00:00:00Z"}',
]);
