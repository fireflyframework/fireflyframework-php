<?php

declare(strict_types=1);

use Firefly\Eda\Listener\EventListenerDescriptor;
use Firefly\Eda\Scanner\EventListenerScanner;
use Firefly\Eda\Tests\ScannerFixtures\SampleListener;

it('scans a #[EventListener] method into a descriptor carrying its patterns + order', function () {
    $descriptors = (new EventListenerScanner)->scan([
        'Firefly\\Eda\\Tests\\ScannerFixtures\\' => __DIR__.'/../ScannerFixtures',
    ]);

    expect($descriptors)->toHaveCount(1);

    $descriptor = $descriptors[0];
    expect($descriptor)->toBeInstanceOf(EventListenerDescriptor::class)
        ->and($descriptor->class)->toBe(SampleListener::class)
        ->and($descriptor->method)->toBe('on')
        ->and($descriptor->patterns)->toBe(['user.*', 'order.created'])
        ->and($descriptor->order)->toBe(5);
});

it('returns an empty list for a directory with no #[EventListener] methods', function () {
    expect((new EventListenerScanner)->scan(['Firefly\\Eda\\' => __DIR__.'/../../src']))->toBe([]);
});
