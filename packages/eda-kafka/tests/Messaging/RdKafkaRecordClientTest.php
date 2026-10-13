<?php

declare(strict_types=1);

use Firefly\Eda\Kafka\Messaging\RdKafkaRecordClient;

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
