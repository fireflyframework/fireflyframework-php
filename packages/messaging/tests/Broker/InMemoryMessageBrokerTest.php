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

it('gives each DISTINCT consumer group its own copy, round-robining within each independently', function () {
    $broker = new InMemoryMessageBroker;
    $log = [];

    // Two named groups on the same topic. Every message must reach BOTH groups (each its own copy);
    // within a group the two members alternate.
    $broker->subscribe('orders', function () use (&$log): void {
        $log[] = 'a1';
    }, 'a');
    $broker->subscribe('orders', function () use (&$log): void {
        $log[] = 'a2';
    }, 'a');
    $broker->subscribe('orders', function () use (&$log): void {
        $log[] = 'b1';
    }, 'b');
    $broker->subscribe('orders', function () use (&$log): void {
        $log[] = 'b2';
    }, 'b');

    $broker->start();
    $broker->publish('orders', 'm1');
    $broker->publish('orders', 'm2');

    // Assert per-group (robust to cross-group iteration order): each group got BOTH messages, round-robined.
    // Delivering to only the first group -> group 'b' empty; broadcasting within a group -> group 'a' has 4.
    $a = array_values(array_filter($log, static fn (string $x): bool => str_starts_with($x, 'a')));
    $b = array_values(array_filter($log, static fn (string $x): bool => str_starts_with($x, 'b')));
    expect($a)->toBe(['a1', 'a2'])
        ->and($b)->toBe(['b1', 'b2'])
        ->and($log)->toHaveCount(4);
});

it('delivers every message to EACH groupless subscriber independently (not a shared round-robin)', function () {
    $broker = new InMemoryMessageBroker;
    $log = [];

    $broker->subscribe('orders', function () use (&$log): void {
        $log[] = 'x';
    }); // groupless
    $broker->subscribe('orders', function () use (&$log): void {
        $log[] = 'y';
    }); // groupless

    $broker->start();
    $broker->publish('orders', 'm1');
    $broker->publish('orders', 'm2');

    // Each groupless subscriber is its own broadcast destination: both receive BOTH messages. Routing
    // groupless subs through a shared round-robin bucket would make them alternate (y misses m1).
    $x = array_values(array_filter($log, static fn (string $v): bool => $v === 'x'));
    $y = array_values(array_filter($log, static fn (string $v): bool => $v === 'y'));
    expect($x)->toBe(['x', 'x'])
        ->and($y)->toBe(['y', 'y'])
        ->and($log)->toHaveCount(4);
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
