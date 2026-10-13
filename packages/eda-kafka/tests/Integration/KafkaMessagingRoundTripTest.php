<?php

declare(strict_types=1);

use Firefly\Eda\Kafka\KafkaConsumerFactory;
use Firefly\Eda\Kafka\KafkaProducerFactory;
use Firefly\Eda\Kafka\Messaging\KafkaMessageBroker;
use Firefly\Eda\Kafka\Messaging\KafkaRecord;
use Firefly\Eda\Kafka\Messaging\KafkaRecordClient;
use Firefly\Eda\Kafka\Messaging\RdKafkaRecordClient;
use Firefly\Eda\Kafka\Tests\Support\KafkaIntegrationTestCase;
use Firefly\Messaging\Container\LinearBackOff;
use Firefly\Messaging\Container\ListenerInvoker;
use Firefly\Messaging\Container\ListenerOptions;
use Firefly\Messaging\Container\RetryPolicy;
use Firefly\Messaging\Message;
use Firefly\Testing\Integration\IntegrationDsn;

uses(KafkaIntegrationTestCase::class);

function w4KafkaMessaging(string $group, int $maxAttempts = 1): KafkaMessageBroker
{
    $brokers = (string) IntegrationDsn::value(IntegrationDsn::KAFKA);

    return new KafkaMessageBroker(
        static fn (string $g): KafkaRecordClient => new RdKafkaRecordClient(new KafkaConsumerFactory($brokers, $g), new KafkaProducerFactory($brokers)),
        new RdKafkaRecordClient(new KafkaConsumerFactory($brokers), new KafkaProducerFactory($brokers)),
        new RetryPolicy($maxAttempts, new LinearBackOff(0.0)),
        new ListenerInvoker(static function (float $seconds): void {}),
        $group,
    );
}

/**
 * Polls until $done says so or 45s pass — a fresh consumer group needs a rebalance before it is assigned. $done
 * must read the test's state BY REFERENCE (`function () use (&$seen)`): an arrow fn captures by value, would never
 * see the listener's writes, and would turn every run into a 45-second wait that still passes.
 */
function w4KafkaPollUntil(KafkaMessageBroker $broker, Closure $done): void
{
    // Seconds, not a loop count: each poll() returns as soon as a record arrives.
    $deadline = microtime(true) + 45;
    while (! $done() && microtime(true) < $deadline) {
        $broker->poll(1000);
    }
}

/** The next record a fresh consumer of $group reads from $topic within 45s, or null. */
function w4KafkaFirstRecord(string $group, string $topic): ?KafkaRecord
{
    $brokers = (string) IntegrationDsn::value(IntegrationDsn::KAFKA);
    $client = new RdKafkaRecordClient(new KafkaConsumerFactory($brokers, $group), new KafkaProducerFactory($brokers));
    $client->subscribe([$topic]);
    $record = null;
    $deadline = microtime(true) + 45;
    while (! $record instanceof KafkaRecord && microtime(true) < $deadline) {
        $record = $client->consume(1000);
    }
    $client->close();

    return $record;
}

it('round-trips publish -> poll -> listener -> commit on a real Kafka, with key and headers', function () {
    $topic = 'firefly.messaging.it.'.bin2hex(random_bytes(4));
    $group = 'firefly-it-'.bin2hex(random_bytes(4));
    $broker = w4KafkaMessaging($group);
    $seen = [];
    $broker->subscribeListener($topic, function (Message $message) use (&$seen): void {
        $seen[] = [$message->value, $message->key, $message->headers['x-trace'] ?? null];
    }, null, new ListenerOptions);

    $broker->publish($topic, 'hello', 'k-1', ['x-trace' => 't-1']);
    w4KafkaPollUntil($broker, function () use (&$seen): bool {
        return $seen !== [];
    });
    $broker->stop();

    // The commit is proven, not assumed: a marker published after it is the FIRST thing a fresh consumer of the
    // same group reads — had the 'hello' offset stayed uncommitted, Kafka would hand 'hello' back first.
    $broker->publish($topic, 'marker');
    $next = w4KafkaFirstRecord($group, $topic);

    expect($seen)->toBe([['hello', 'k-1', 't-1']])
        ->and($next?->value)->toBe('marker');
})->skip(! extension_loaded('rdkafka') || IntegrationDsn::missing(IntegrationDsn::KAFKA), 'Set FIREFLY_KAFKA_BROKERS (and load ext-rdkafka) to run the Kafka messaging round trip.')->group('integration');

it('retries a failing listener, then produces the record to <topic>.DLT with provenance headers', function () {
    $topic = 'firefly.messaging.it.'.bin2hex(random_bytes(4));
    $broker = w4KafkaMessaging('firefly-it-'.bin2hex(random_bytes(4)), maxAttempts: 2);
    $calls = 0;
    $broker->subscribeListener($topic, function () use (&$calls): void {
        $calls++;

        throw new RuntimeException('listener down');
    }, null, new ListenerOptions);

    $broker->publish($topic, 'payload', 'k-9');
    w4KafkaPollUntil($broker, function () use (&$calls): bool {
        return $calls >= 2;
    });
    $broker->stop();

    $dead = w4KafkaFirstRecord('firefly-it-dlt-'.bin2hex(random_bytes(4)), $topic.'.DLT');

    expect($calls)->toBe(2)
        ->and($dead)->toBeInstanceOf(KafkaRecord::class)
        ->and($dead?->value)->toBe('payload')
        ->and($dead?->key)->toBe('k-9')
        ->and($dead?->headers)->toMatchArray([
            'x-original-topic' => $topic,
            'x-exception' => 'RuntimeException',
            'x-exception-message' => 'listener down',
            'x-delivery-attempts' => '2',
            'x-dlt-reason' => 'RuntimeException',
            'x-dlt-source-topic' => $topic,
            'x-dlt-source-offset' => '0',
        ]);
})->skip(! extension_loaded('rdkafka') || IntegrationDsn::missing(IntegrationDsn::KAFKA), 'Set FIREFLY_KAFKA_BROKERS (and load ext-rdkafka) to run the Kafka messaging round trip.')->group('integration');
