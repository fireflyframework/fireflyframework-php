<?php

declare(strict_types=1);

use Firefly\Actuator\Health\Health;
use Firefly\Testing\Double\FakeHealthIndicator;
use Firefly\Testing\Double\RecordingDistributedLock;
use Firefly\Testing\Double\RecordingMessageBroker;
use Firefly\Testing\Double\RecordingTracer;
use PHPUnit\Framework\ExpectationFailedException;

it('records message broker publishes', function () {
    $broker = new RecordingMessageBroker;
    $broker->publish('orders', 'payload', 'k', ['h' => '1']);

    expect($broker->published)->toHaveCount(1)
        ->and($broker->publishedTo('orders'))->toHaveCount(1)
        ->and($broker->published[0]['value'])->toBe('payload');
});

it('records distributed-lock acquire/release and honours availability', function () {
    $lock = new RecordingDistributedLock(available: false);
    expect($lock->tryAcquire('job', 5.0))->toBeFalse()
        ->and($lock->acquired)->toHaveCount(1);

    $lock->setAvailable(true);
    expect($lock->tryAcquire('job', 5.0))->toBeTrue();
    $lock->release('job');
    expect($lock->released)->toBe(['job']);
});

it('exposes a programmable health indicator with toBeUp', function () {
    $indicator = new FakeHealthIndicator;

    // @phpstan-ignore method.notFound
    expect($indicator->health())->toBeUp();
    // @phpstan-ignore method.notFound
    expect($indicator)->toBeUp();
});

it('fails the toBeUp expectation when the health indicator is down', function () {
    $indicator = (new FakeHealthIndicator)->setHealth(Health::down(['reason' => 'x']));

    expect(function () use ($indicator): void {
        // @phpstan-ignore method.notFound
        expect($indicator)->toBeUp();
    })->toThrow(ExpectationFailedException::class);
});

it('traces by invoking the callback and recording the span name', function () {
    $tracer = new RecordingTracer;
    $result = $tracer->trace('work', fn (): int => 7);

    expect($result)->toBe(7)
        ->and($tracer->spans)->toBe(['work']);
});
