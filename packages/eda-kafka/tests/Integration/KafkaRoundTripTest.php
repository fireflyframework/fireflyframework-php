<?php

declare(strict_types=1);

use Firefly\Eda\Bus\SubscriberRegistry;
use Firefly\Eda\Consumer\ConsumerLoop;
use Firefly\Eda\Consumer\ConsumerOptions;
use Firefly\Eda\EventEnvelope;
use Firefly\Eda\JsonSerializer;
use Firefly\Eda\Kafka\KafkaConsumerFactory;
use Firefly\Eda\Kafka\KafkaEventConsumer;
use Firefly\Eda\Kafka\KafkaEventPublisher;
use Firefly\Eda\Kafka\KafkaProducerFactory;
use Firefly\Eda\Kafka\RdKafkaConsumerClient;
use Firefly\Eda\Kafka\Tests\Support\KafkaIntegrationTestCase;

uses(KafkaIntegrationTestCase::class);

/**
 * Real Kafka round-trip, DOUBLE-gated (like RabbitMqRoundTripTest / PostgresOutboxRoundTripTest): the file-level
 * `->skip(...)` below (evaluated eagerly at collection time) requires BOTH ext-rdkafka and FIREFLY_KAFKA_BROKERS,
 * so this suite never even reaches setUp() — and thus never calls skipUnlessDocker() — on a no-ext machine such as
 * this one. `uses(KafkaIntegrationTestCase::class)` (not a bare `uses(RequiresDocker::class)`, per its own
 * docblock) is what runs the Docker check safely once both env preconditions are met.
 *
 * Test 1 proves the full publish -> consume -> handler -> commit path AND that ack() genuinely committed the
 * offset: a SECOND, independent KafkaEventConsumer built with the SAME group.id polls the same topic again and
 * must see nothing — Kafka's own redelivery would prove the commit did NOT happen.
 *
 * Test 2 proves nack(requeue: false) is Kafka's DEAD-LETTER TOPIC path (no broker-native DLX, unlike RabbitMQ): a
 * forced dead-letter re-produces the envelope to "<topic>.DLT", verified by consuming that topic directly.
 */
it('round-trips publish -> consume -> handler -> commit against a real Kafka, and commit() prevents redelivery', function () {
    $brokers = (string) getenv('FIREFLY_KAFKA_BROKERS');
    $topic = 'firefly.eda.test.'.bin2hex(random_bytes(4));
    $groupId = 'firefly-test-'.bin2hex(random_bytes(4));

    $registry = new SubscriberRegistry;
    $received = [];
    $registry->subscribe('order.*', function (EventEnvelope $envelope) use (&$received): void {
        $received[] = $envelope->payload['id'];
    });

    $producerFactory = new KafkaProducerFactory($brokers);
    $consumerFactory = new KafkaConsumerFactory($brokers, $groupId);

    $consumer = new KafkaEventConsumer(new RdKafkaConsumerClient($consumerFactory, $producerFactory, new JsonSerializer));
    $consumer->subscribe([$topic]);
    $consumer->start();

    $publisher = new KafkaEventPublisher($producerFactory, $registry, new JsonSerializer);
    $publisher->start();
    $publisher->publish($topic, 'order.created', ['id' => 42]);
    $publisher->stop();

    $processed = (new ConsumerLoop)->run(
        $consumer,
        fn (EventEnvelope $envelope) => $registry->deliver($envelope),
        new ConsumerOptions(maxMessages: 1, timeLimit: 20, pollTimeoutMs: 2000),
    );

    expect($processed)->toBe(1)->and($received)->toBe([42]);

    // Prove ack() genuinely committed: a fresh consumer with the SAME group.id must NOT see the record again.
    $verifyConsumer = new KafkaEventConsumer(new RdKafkaConsumerClient($consumerFactory, $producerFactory, new JsonSerializer));
    $verifyConsumer->subscribe([$topic]);
    $verifyConsumer->start();
    $again = $verifyConsumer->poll(3000);
    $verifyConsumer->stop();

    expect($again)->toBeNull();
})->skip(
    ! extension_loaded('rdkafka') || getenv('FIREFLY_KAFKA_BROKERS') === false,
    'requires ext-rdkafka + FIREFLY_KAFKA_BROKERS',
)->group('integration');

it('routes an exhausted retry (nack requeue:false) to the "<topic>.DLT" dead-letter topic', function () {
    $brokers = (string) getenv('FIREFLY_KAFKA_BROKERS');
    $topic = 'firefly.eda.test.'.bin2hex(random_bytes(4));
    $dltTopic = $topic.'.DLT';

    $producerFactory = new KafkaProducerFactory($brokers);
    $consumerFactory = new KafkaConsumerFactory($brokers, 'firefly-test-dlt-'.bin2hex(random_bytes(4)));

    $consumer = new KafkaEventConsumer(new RdKafkaConsumerClient($consumerFactory, $producerFactory, new JsonSerializer));
    $consumer->subscribe([$topic]);
    $consumer->start();

    $publisher = new KafkaEventPublisher($producerFactory, new SubscriberRegistry, new JsonSerializer);
    $publisher->start();
    $publisher->publish($topic, 'order.created', ['id' => 99]);
    $publisher->stop();

    $received = null;
    $deadline = time() + 15;
    while ($received === null && time() < $deadline) {
        $received = $consumer->poll(2000);
    }
    if ($received === null) {
        throw new RuntimeException('Expected the published message to be received before the deadline.');
    }

    // A handler that always fails, exhausted -> the caller dead-letters via nack(requeue: false) instead of
    // ConsumerLoop's default nack(requeue: true) at-least-once retry.
    $consumer->nack($received, false);
    $consumer->stop();

    $dltConsumerFactory = new KafkaConsumerFactory($brokers, 'firefly-test-dlt-verify-'.bin2hex(random_bytes(4)));
    $dltConsumer = new KafkaEventConsumer(new RdKafkaConsumerClient($dltConsumerFactory, $producerFactory, new JsonSerializer));
    $dltConsumer->subscribe([$dltTopic]);
    $dltConsumer->start();

    $dltReceived = null;
    $deadline = time() + 15;
    while ($dltReceived === null && time() < $deadline) {
        $dltReceived = $dltConsumer->poll(2000);
    }
    $dltConsumer->stop();

    if ($dltReceived === null) {
        throw new RuntimeException('Expected a dead-lettered message on the "<topic>.DLT" topic before the deadline.');
    }

    expect($dltReceived->envelope->payload['id'] ?? null)->toBe(99);
})->skip(
    ! extension_loaded('rdkafka') || getenv('FIREFLY_KAFKA_BROKERS') === false,
    'requires ext-rdkafka + FIREFLY_KAFKA_BROKERS',
)->group('integration');
