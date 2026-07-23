<?php

declare(strict_types=1);

use Firefly\Eda\DeadLetter\InMemoryDeadLetterStore;
use Firefly\Eda\DeadLetter\RetryingEventHandler;
use Firefly\Eda\EventEnvelope;

function envelopeFor(string $type = 'order.placed'): EventEnvelope
{
    return new EventEnvelope($type, 'firefly.events', ['n' => 1]);
}

it('runs the handler once and re-throws when retries=0 and dlq=null', function () {
    $wrapped = RetryingEventHandler::wrap(function (): void {
        throw new RuntimeException('boom');
    }, 0, 0.0, null);

    $wrapped(envelopeFor());
})->throws(RuntimeException::class, 'boom');

it('does not retry on success and passes the envelope through', function () {
    $seen = null;
    $calls = 0;
    $wrapped = RetryingEventHandler::wrap(function (EventEnvelope $e) use (&$seen, &$calls): void {
        $calls++;
        $seen = $e;
    }, 3, 0.0, null);

    $envelope = envelopeFor();
    $wrapped($envelope);

    expect($calls)->toBe(1)->and($seen)->toBe($envelope);
});

it('retries up to $retries additional times then DLQs with x-original-topic + x-exception headers', function () {
    $attempts = 0;
    $dlq = new InMemoryDeadLetterStore;
    $wrapped = RetryingEventHandler::wrap(function () use (&$attempts): void {
        $attempts++;
        throw new RuntimeException('always fails');
    }, 2, 0.0, $dlq);

    $wrapped(envelopeFor('order.placed'));

    expect($attempts)->toBe(3) // 1 initial + 2 retries
        ->and($dlq->all())->toHaveCount(1);

    $entry = $dlq->all()[0];
    expect($entry->envelope->headers['x-original-topic'])->toBe('firefly.events')
        ->and($entry->envelope->headers['x-exception'])->toBe('always fails')
        ->and($entry->exceptionMessage)->toBe('always fails')
        ->and($entry->exceptionClass)->toBe(RuntimeException::class);
});

it('re-throws after exhausting retries when no dlq is configured', function () {
    $wrapped = RetryingEventHandler::wrap(function (): void {
        throw new LogicException('nope');
    }, 1, 0.0, null);

    $wrapped(envelopeFor());
})->throws(LogicException::class, 'nope');

it('stops retrying as soon as an attempt succeeds', function () {
    $attempts = 0;
    $wrapped = RetryingEventHandler::wrap(function () use (&$attempts): void {
        $attempts++;
        if ($attempts < 2) {
            throw new RuntimeException('transient');
        }
    }, 5, 0.0, null);

    $wrapped(envelopeFor());

    expect($attempts)->toBe(2); // failed once, succeeded on the retry, no further attempts
});
