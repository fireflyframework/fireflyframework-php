<?php

declare(strict_types=1);

use Firefly\Eda\Bus\SubscriberRegistry;
use Firefly\Eda\JsonSerializer;
use Firefly\Eda\Kafka\KafkaEventPublisher;
use Firefly\Eda\Kafka\KafkaProducerFactory;
use PHPUnit\Framework\Assert;

it('refuses to build a producer with a clear message when rdkafka is missing', function () {
    if (extension_loaded('rdkafka')) {
        Assert::markTestSkipped('rdkafka present — the guard path is not exercised.');
    }

    expect(fn () => (new KafkaProducerFactory('127.0.0.1:9092'))->producer())
        ->toThrow(RuntimeException::class, 'ext-rdkafka');
});

/**
 * The publisher's own guard, which is reachable with the ext present or absent: the factory is nullable ONLY so
 * the pure-PHP unit tests can hand in a FakeKafkaProducerClient instead, and a publisher constructed with neither
 * is a programming error, not a runtime condition. It has to say so rather than fail on a null dereference.
 */
it('refuses to publish with neither a producer factory nor a client override', function () {
    $publisher = new KafkaEventPublisher(null, new SubscriberRegistry, new JsonSerializer);

    expect(fn () => $publisher->publish('orders', 'order.created', ['id' => 1]))
        ->toThrow(RuntimeException::class, 'no producer factory and no client override');
});
