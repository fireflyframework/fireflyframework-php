<?php

declare(strict_types=1);

use Firefly\Eda\Bus\SubscriberRegistry;
use Firefly\Eda\EventEnvelope;

it('delivers to fnmatch-matching subscribers, in subscription order, skipping non-matches', function () {
    $registry = new SubscriberRegistry;
    $log = [];

    $registry->subscribe('user.*', function (EventEnvelope $e) use (&$log): void {
        $log[] = 'wild:'.$e->eventType;
    });
    $registry->subscribe('user.created', function (EventEnvelope $e) use (&$log): void {
        $log[] = 'exact:'.$e->eventType;
    });
    $registry->subscribe('order.*', function () use (&$log): void {
        $log[] = 'order';
    });

    $registry->deliver(new EventEnvelope('user.created', 'firefly.events'));

    expect($log)->toBe(['wild:user.created', 'exact:user.created']); // order.* never fires
});
