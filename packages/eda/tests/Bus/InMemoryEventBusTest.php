<?php

declare(strict_types=1);

use Firefly\Eda\Bus\InMemoryEventBus;
use Firefly\Eda\Bus\SubscriberRegistry;
use Firefly\Eda\EventEnvelope;

it('publishes synchronously to matching subscribers with a well-formed envelope', function () {
    $bus = new InMemoryEventBus(new SubscriberRegistry);
    $received = [];

    $bus->subscribe('order.*', function (EventEnvelope $e) use (&$received): void {
        $received[] = $e;
    });
    $bus->start();
    $bus->publish('firefly.events', 'order.placed', ['id' => 9], ['x-trace' => 'z']);

    expect($received)->toHaveCount(1)
        ->and($received[0]->eventType)->toBe('order.placed')
        ->and($received[0]->destination)->toBe('firefly.events')
        ->and($received[0]->payload)->toBe(['id' => 9])
        ->and($received[0]->headers)->toBe(['x-trace' => 'z']);

    $bus->stop();
});
