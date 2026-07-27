<?php

declare(strict_types=1);

use Firefly\Testing\Fixture\ListenerSpy;

it('appends every recorded value to $seen, in order', function () {
    $spy = new ListenerSpy;

    $spy->record('order.placed');
    $spy->record('order.shipped');

    expect($spy->seen)->toBe(['order.placed', 'order.shipped']);
});

it('starts with an empty $seen list', function () {
    expect((new ListenerSpy)->seen)->toBe([]);
});
