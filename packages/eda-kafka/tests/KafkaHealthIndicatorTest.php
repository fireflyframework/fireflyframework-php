<?php

declare(strict_types=1);

use Firefly\Actuator\Health\Status;
use Firefly\Eda\Kafka\KafkaHealthIndicator;

it('reports DOWN with an ext-missing detail when rdkafka is absent', function () {
    if (extension_loaded('rdkafka')) {
        $this->markTestSkipped('rdkafka present.');
    }

    $health = (new KafkaHealthIndicator('127.0.0.1:9092'))->health();
    expect($health->status)->toBe(Status::Down)
        ->and($health->details['error'])->toContain('rdkafka');
});
