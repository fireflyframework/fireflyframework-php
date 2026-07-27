<?php

declare(strict_types=1);

use Firefly\Eda\Kafka\KafkaEventPublisher;

it('prefers partition_key, then x-correlation-id, then the event type', function () {
    expect(KafkaEventPublisher::partitionKey('e', ['partition_key' => 'p', 'x-correlation-id' => 'c']))->toBe('p')
        ->and(KafkaEventPublisher::partitionKey('e', ['x-correlation-id' => 'c']))->toBe('c')
        ->and(KafkaEventPublisher::partitionKey('order.created', []))->toBe('order.created');
});
