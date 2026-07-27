<?php

declare(strict_types=1);

use Firefly\Testing\Double\RecordingEventPublisher;

it('records publishes in the FakeEventPublisher-compatible shape', function () {
    $publisher = new RecordingEventPublisher;
    $publisher->publish('accounts.events', 'AccountOpened', ['owner' => 'alice'], ['x' => '1']);

    expect($publisher->published)->toHaveCount(1)
        ->and($publisher->published[0]['destination'])->toBe('accounts.events')
        ->and($publisher->published[0]['eventType'])->toBe('AccountOpened')
        ->and($publisher->published[0]['payload']['owner'])->toBe('alice')
        ->and($publisher->published[0]['headers']['x'])->toBe('1');
});

it('supports the toHavePublished expectation with a payload subset', function () {
    $publisher = new RecordingEventPublisher;
    $publisher->publish('accounts.events', 'AccountOpened', ['owner' => 'alice', 'balance' => 500]);

    // toHavePublished() is registered at runtime by FireflyExpectations::register() (tests/Pest.php),
    // invisible to PHPStan's static reflection of the vendor Pest\Expectation class — see the matching
    // note in functions.php's assertEventPublished().
    // @phpstan-ignore method.notFound
    expect($publisher)->toHavePublished('AccountOpened', payloadContains: ['owner' => 'alice']);
});

it('supports the procedural assertions', function () {
    $publisher = new RecordingEventPublisher;
    assertNoEventsPublished($publisher);

    $publisher->publish('d', 'Thing', ['id' => 7]);
    assertEventPublished($publisher, 'Thing', ['id' => 7]);
});
