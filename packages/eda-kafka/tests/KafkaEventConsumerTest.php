<?php

declare(strict_types=1);

use Firefly\Eda\Consumer\ReceivedEnvelope;
use Firefly\Eda\EventEnvelope;
use Firefly\Eda\Kafka\KafkaEventConsumer;
use Firefly\Eda\Kafka\Tests\Fixtures\FakeKafkaConsumerClient;

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
