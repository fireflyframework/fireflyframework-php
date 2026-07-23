<?php

declare(strict_types=1);

use Firefly\Messaging\Broker\InMemoryMessageBroker;
use Firefly\Messaging\Exception\MessagingException;
use Firefly\Messaging\Message;

it('throws when publishing before start()', function () {
    (new InMemoryMessageBroker)->publish('orders', 'x');
})->throws(MessagingException::class);

it('broadcasts to groupless subscribers and round-robins within a named group', function () {
    $broker = new InMemoryMessageBroker;
    $log = [];

    $broker->subscribe('orders', function (Message $m) use (&$log): void {
        $log[] = 'broadcast:'.$m->value;
    }); // no group → every message
    $broker->subscribe('orders', function () use (&$log): void {
        $log[] = 'g-A';
    }, 'workers');
    $broker->subscribe('orders', function () use (&$log): void {
        $log[] = 'g-B';
    }, 'workers');

    $broker->start();
    $broker->publish('orders', 'm1');
    $broker->publish('orders', 'm2');
    $broker->publish('orders', 'm3');

    // broadcast fires every publish; the "workers" group alternates A, B, A across the 3 publishes.
    expect($log)->toBe([
        'broadcast:m1', 'g-A',
        'broadcast:m2', 'g-B',
        'broadcast:m3', 'g-A',
    ]);
});

it('delivers a well-formed Message to subscribers', function () {
    $broker = new InMemoryMessageBroker;
    $received = null;
    $broker->subscribe('payments', function (Message $m) use (&$received): void {
        $received = $m;
    });
    $broker->start();
    $broker->publish('payments', 'bytes', 'key-1', ['h' => 'v']);

    /** @var Message $received */
    expect($received)->toBeInstanceOf(Message::class)
        ->and($received->topic)->toBe('payments')
        ->and($received->value)->toBe('bytes')
        ->and($received->key)->toBe('key-1')
        ->and($received->headers)->toBe(['h' => 'v']);
});
