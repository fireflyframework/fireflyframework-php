<?php

declare(strict_types=1);

use Firefly\Eda\Kafka\KafkaConsumerFactory;

it('refuses to build a consumer with a clear message when rdkafka is missing', function () {
    if (extension_loaded('rdkafka')) {
        $this->markTestSkipped('rdkafka present — the guard path is not exercised.');
    }

    expect(fn () => (new KafkaConsumerFactory('127.0.0.1:9092', 'firefly'))->consumer())
        ->toThrow(RuntimeException::class, 'ext-rdkafka');
});
