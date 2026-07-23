<?php

declare(strict_types=1);

use Firefly\Messaging\Listener\MessageListenerDescriptor;
use Firefly\Messaging\Scanner\MessageListenerScanner;
use Firefly\Messaging\Tests\ScannerFixtures\SampleConsumer;

it('scans a #[MessageListener] method into a descriptor carrying topic/group/retries/retryDelay/deadLetterTopic', function () {
    $descriptors = (new MessageListenerScanner)->scan([
        'Firefly\\Messaging\\Tests\\ScannerFixtures\\' => __DIR__.'/../ScannerFixtures',
    ]);

    expect($descriptors)->toHaveCount(1);

    $descriptor = $descriptors[0];
    expect($descriptor)->toBeInstanceOf(MessageListenerDescriptor::class)
        ->and($descriptor->class)->toBe(SampleConsumer::class)
        ->and($descriptor->method)->toBe('consume')
        ->and($descriptor->topic)->toBe('orders')
        ->and($descriptor->group)->toBe('workers')
        ->and($descriptor->retries)->toBe(3)
        ->and($descriptor->retryDelay)->toBe(0.5)
        ->and($descriptor->deadLetterTopic)->toBe('orders.DLT');
});

it('returns an empty list for a directory with no #[MessageListener] methods', function () {
    expect((new MessageListenerScanner)->scan(['Firefly\\Messaging\\' => __DIR__.'/../../src']))->toBe([]);
});
