<?php

declare(strict_types=1);

use Firefly\Eda\Consumer\TopicSubscriptionResolver;
use Firefly\Eda\Listener\EventListenerDescriptor;
use Firefly\Eda\Listener\EventListenerManifest;

it('collects distinct fnmatch patterns from the compiled manifest', function () {
    $manifest = new EventListenerManifest([
        new EventListenerDescriptor('A', 'on', ['user.*', 'order.created'], 0),
        new EventListenerDescriptor('B', 'on', ['user.*'], 0),
    ]);

    expect((new TopicSubscriptionResolver)->resolve($manifest))
        ->toBe(['user.*', 'order.created']);
});
