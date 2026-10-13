<?php

declare(strict_types=1);

use Firefly\Eda\Kafka\KafkaConsumerFactory;
use Firefly\Eda\Kafka\KafkaProducerFactory;
use Firefly\Eda\Kafka\Messaging\RdKafkaRecordClient;
use Firefly\Messaging\Exception\MessagingException;
use RdKafka\Message;
use RdKafka\Producer;

function w4RecordClient(): RdKafkaRecordClient
{
    return new RdKafkaRecordClient(new KafkaConsumerFactory('127.0.0.1:59999', 'firefly-dr-probe'), new KafkaProducerFactory('127.0.0.1:59999'));
}

it('maps a consumed payload into a record, keeping only string-valued headers', function () {
    $record = RdKafkaRecordClient::record('orders', 'v', 'k', ['a' => '1', 'b' => 2, 'c' => null], 3, 41, 'handle');

    expect($record->topic)->toBe('orders')
        ->and($record->value)->toBe('v')
        ->and($record->key)->toBe('k')
        ->and($record->headers)->toBe(['a' => '1', 'b' => '2'])
        ->and($record->partition)->toBe(3)
        ->and($record->offset)->toBe(41)
        ->and($record->handle)->toBe('handle');
});

it('maps a tombstone (null payload) to an empty value', function () {
    expect(RdKafkaRecordClient::record('orders', null, null, null, 0, 0, null)->value)->toBe('');
});

it('throws from the next flush() when a delivery report said a produced record failed, then forgets it', function () {
    $client = w4RecordClient();
    $client->delivered(0, 'Success', 'orders');
    $client->flush(10);

    $client->delivered(29, 'Broker: Topic authorization failed', 'orders.DLT');

    expect(fn () => $client->flush(10))->toThrow(MessagingException::class, '[orders.DLT] Broker: Topic authorization failed (librdkafka error 29)');
    $client->flush(10);
});

/*
 * The wiring half, against the real ext-rdkafka and no broker: a message queued for an unroutable bootstrap address
 * and then purged from the queue fails delivery with ERR__PURGE_QUEUE, which librdkafka reports ONLY to dr_msg_cb —
 * while flush() itself answers NO_ERROR, because the out-queue is empty. That is exactly the gap a callback-less
 * producer leaves open.
 */
it('hands every delivery report to the callback given to KafkaProducerFactory, failures flush() does not report', function () {
    $reports = [];
    $producer = (new KafkaProducerFactory('127.0.0.1:59999'))->producer(function (Producer $producer, Message $message) use (&$reports): void {
        $reports[] = $message->err;
    });
    $producer->newTopic('orders')->producev(RD_KAFKA_PARTITION_UA, 0, 'v');
    $producer->purge(RD_KAFKA_PURGE_F_QUEUE);

    expect($producer->flush(2000))->toBe(RD_KAFKA_RESP_ERR_NO_ERROR)
        ->and($reports)->toBe([RD_KAFKA_RESP_ERR__PURGE_QUEUE]);
})->skip(! extension_loaded('rdkafka'), 'needs ext-rdkafka');
