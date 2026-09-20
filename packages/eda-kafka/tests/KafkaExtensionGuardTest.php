<?php

declare(strict_types=1);

use Firefly\Eda\Kafka\KafkaProducerFactory;
use PHPUnit\Framework\Assert;

it('refuses to build a producer with a clear message when rdkafka is missing', function () {
    if (extension_loaded('rdkafka')) {
        Assert::markTestSkipped('rdkafka present — the guard path is not exercised.');
    }

    expect(fn () => (new KafkaProducerFactory('127.0.0.1:9092'))->producer())
        ->toThrow(RuntimeException::class, 'ext-rdkafka');
});
