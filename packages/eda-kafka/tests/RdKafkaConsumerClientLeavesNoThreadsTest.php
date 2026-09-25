<?php

declare(strict_types=1);

use Firefly\Eda\JsonSerializer;
use Firefly\Eda\Kafka\KafkaConsumerFactory;
use Firefly\Eda\Kafka\KafkaProducerFactory;
use Firefly\Eda\Kafka\RdKafkaConsumerClient;

/**
 * `RdKafkaConsumerClient::close()` MUST NOT STRAND ITS librdkafka CLIENT.
 *
 * It used to call `KafkaConsumer::close()` before releasing the property, which reads like a careful
 * teardown and is a leak. ext-rdkafka 6.0.5, kafka_consumer.c:531-542 — the whole method:
 *
 *     rd_kafka_consumer_close(intern->rk);
 *     intern->rk = NULL;
 *
 * No `rd_kafka_destroy()`, and the free handler at kafka_consumer.c:53-64 destroys the handle only
 * `if (intern->rk)`. So after `close()` the PHP object is freed and the `rd_kafka_t` is not: it and
 * its four OS threads live until the process exits. In a daemon — which is what this client is for —
 * that accumulates for as long as the process runs.
 *
 * NO BROKER IS NEEDED and none is contacted. `new KafkaConsumer($conf)` starts librdkafka's threads
 * and resolves the bootstrap address lazily, so an unroutable port costs nothing and keeps this test
 * out of the docker-gated integration suite where KafkaRoundTripTest lives.
 *
 * `rd_kafka_thread_cnt()` IS THE INSTRUMENT, and the choice matters. A WeakReference assertion — the
 * natural way to test that an object was released — is GREEN on this bug, because the PHP object
 * really is freed; the leak is underneath it, in C. Only a process-global count of live handles can
 * see it.
 */
it('destroys the librdkafka client when the consumer is closed, leaving no threads behind', function () {
    $antes = rd_kafka_thread_cnt();

    for ($i = 0; $i < 3; $i++) {
        $client = new RdKafkaConsumerClient(
            new KafkaConsumerFactory('127.0.0.1:59999', 'firefly-leak-probe-'.bin2hex(random_bytes(4))),
            new KafkaProducerFactory('127.0.0.1:59999'),
            new JsonSerializer,
        );

        $client->subscribe(['firefly-leak-probe-topic']);
        $client->close();

        unset($client);
    }

    // Half a second in case any part of the teardown were asynchronous. It is not — the count comes
    // back at once — but a test that fails on a scheduler hiccup is worse than a slightly slow one.
    usleep(500_000);

    expect(rd_kafka_thread_cnt())->toBe(
        $antes,
        'the client was closed and its librdkafka handle outlived it. The usual cause is a '
        .'`KafkaConsumer::close()` call: ext-rdkafka nulls the handle without destroying it, so '
        .'closing strands the client and its threads. Call unsubscribe() and release the reference.',
    );
})->skip(
    // Eagerly, at collection time, exactly as KafkaRoundTripTest gates itself: on a machine without
    // the extension there is no handle to leak and nothing to count. No broker is required, so
    // unlike that suite this one does NOT ask for FIREFLY_KAFKA_BROKERS.
    ! extension_loaded('rdkafka') || ! function_exists('rd_kafka_thread_cnt'),
    'ext-rdkafka is what leaks; without it there is nothing to count.',
);
