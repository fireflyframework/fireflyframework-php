<?php

declare(strict_types=1);

use Firefly\Eda\Listener\EventListenerDescriptor;
use Firefly\Eda\Scanner\EventListenerScanner;
use Firefly\Eda\Tests\ScannerFixtures\PlainListener;
use Firefly\Eda\Tests\ScannerFixtures\SampleListener;

/**
 * The scanner is the sole reflection site, so anything it drops is gone from every downstream stage. `order` was
 * already carried here (and was then discarded at dispatch — see EventListenerManifest::ordered()); `destinations`
 * is carried for the same reason and must not be quietly lost either, because TopicSubscriptionResolver can only
 * narrow a broker subscription from what reaches the compiled manifest. PlainListener pins the other half: a
 * listener that declares no destinations compiles to an empty list, which the resolver reads as "cannot narrow".
 */
it('scans a #[EventListener] method into a descriptor carrying its patterns + order + destinations', function () {
    $descriptors = (new EventListenerScanner)->scan([
        'Firefly\\Eda\\Tests\\ScannerFixtures\\' => __DIR__.'/../ScannerFixtures',
    ]);

    // EventListenerScanner::classes() sort()s the discovered FQCNs, so PlainListener precedes SampleListener.
    expect($descriptors)->toHaveCount(2);

    [$plain, $descriptor] = $descriptors;
    expect($descriptor)->toBeInstanceOf(EventListenerDescriptor::class)
        ->and($descriptor->class)->toBe(SampleListener::class)
        ->and($descriptor->method)->toBe('on')
        ->and($descriptor->patterns)->toBe(['user.*', 'order.created'])
        ->and($descriptor->order)->toBe(5)
        ->and($descriptor->destinations)->toBe(['users', 'orders'])
        ->and($plain->class)->toBe(PlainListener::class)
        ->and($plain->destinations)->toBe([]);
});

it('returns an empty list for a directory with no #[EventListener] methods', function () {
    expect((new EventListenerScanner)->scan(['Firefly\\Eda\\' => __DIR__.'/../../src']))->toBe([]);
});
