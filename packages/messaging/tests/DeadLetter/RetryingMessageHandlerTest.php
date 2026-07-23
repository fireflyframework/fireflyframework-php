<?php

declare(strict_types=1);

use Firefly\Messaging\DeadLetter\InMemoryDeadLetterStore;
use Firefly\Messaging\DeadLetter\RetryingMessageHandler;
use Firefly\Messaging\Message;

function inMsg(string $topic = 'orders'): Message
{
    return new Message($topic, 'payload', 'k1');
}

it('runs the handler once and re-throws when retries=0 and deadLetterTopic=null', function () {
    $wrapped = RetryingMessageHandler::wrap(function (): void {
        throw new RuntimeException('boom');
    }, 0, 0.0, null, new InMemoryDeadLetterStore);

    $wrapped(inMsg());
})->throws(RuntimeException::class, 'boom');

it('does not retry on success and passes the message through', function () {
    $seen = null;
    $calls = 0;
    $dlq = new InMemoryDeadLetterStore;
    $wrapped = RetryingMessageHandler::wrap(function (Message $m) use (&$seen, &$calls): void {
        $calls++;
        $seen = $m;
    }, 3, 0.0, 'orders.DLT', $dlq);

    $message = inMsg();
    $wrapped($message);

    expect($calls)->toBe(1)
        ->and($seen)->toBe($message)
        ->and($dlq->all())->toHaveCount(0);
});

it('stops retrying as soon as an attempt succeeds, within the retry budget', function () {
    $attempts = 0;
    $dlq = new InMemoryDeadLetterStore;
    $wrapped = RetryingMessageHandler::wrap(function () use (&$attempts): void {
        $attempts++;
        if ($attempts < 2) {
            throw new RuntimeException('transient');
        }
    }, 5, 0.0, 'orders.DLT', $dlq);

    $wrapped(inMsg());

    expect($attempts)->toBe(2) // failed once, succeeded on the retry, no further attempts
        ->and($dlq->all())->toHaveCount(0);
});

it('retries then re-keys to the dead-letter topic with x-original-topic + x-exception headers', function () {
    $attempts = 0;
    $dlq = new InMemoryDeadLetterStore;
    $wrapped = RetryingMessageHandler::wrap(function () use (&$attempts): void {
        $attempts++;
        throw new RuntimeException('consumer failed');
    }, 2, 0.0, 'orders.DLT', $dlq);

    $wrapped(inMsg('orders'));

    expect($attempts)->toBe(3) // 1 + 2 retries
        ->and($dlq->all())->toHaveCount(1);

    $stored = $dlq->all()[0]->message;
    expect($stored->topic)->toBe('orders.DLT')
        ->and($stored->key)->toBe('k1')
        ->and($stored->value)->toBe('payload')
        ->and($stored->headers['x-original-topic'])->toBe('orders')
        ->and($stored->headers['x-exception'])->toBe('consumer failed')
        ->and($dlq->all()[0]->exceptionClass)->toBe(RuntimeException::class);
});

it('re-throws after exhausting retries when deadLetterTopic is null', function () {
    $wrapped = RetryingMessageHandler::wrap(function (): void {
        throw new LogicException('nope');
    }, 1, 0.0, null, new InMemoryDeadLetterStore);

    $wrapped(inMsg());
})->throws(LogicException::class, 'nope');
