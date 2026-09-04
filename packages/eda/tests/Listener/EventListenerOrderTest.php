<?php

declare(strict_types=1);

use Firefly\Eda\Listener\EventListenerDescriptor;
use Firefly\Eda\Listener\EventListenerManifest;

/**
 * ORDERING CONTRACT (unit half). #[EventListener(order: N)] was scanned, compiled into the manifest, and then
 * never consulted again: EventListenerWiringPass looped over EventListenerManifest::all() — raw compiled order —
 * and SubscriberRegistry::deliver() fires handlers in subscription order, so the declared order was silently
 * discarded and dispatch sequence degenerated to "whatever the scanner emitted" (FQCN sort + declaration order).
 * EventListenerManifest::ordered() is the single sorting seam that closed that gap; these cases pin both halves
 * of its contract: ascending by order, and a STABLE tie-break that keeps compiled-manifest order for equal orders.
 */
it('sorts listeners by their declared order, ascending', function () {
    $manifest = new EventListenerManifest([
        new EventListenerDescriptor('C', 'on', ['x'], 30),
        new EventListenerDescriptor('A', 'on', ['x'], -5),
        new EventListenerDescriptor('B', 'on', ['x'], 0),
    ]);

    expect(array_map(static fn ($d) => $d->class, $manifest->ordered()))->toBe(['A', 'B', 'C'])
        ->and(array_map(static fn ($d) => $d->class, $manifest->all()))->toBe(['C', 'A', 'B']);
});

/**
 * The tie-break has to be DETERMINISTIC or "ordered" is a half-promise: two listeners at the same order would
 * swap places between runs and an app could pass CI and fail in production. usort() is guaranteed stable as of
 * PHP 8.0, so equal orders keep the compiled-manifest sequence — which is itself deterministic, because
 * EventListenerScanner sort()s the discovered FQCNs and reflection returns a class's methods in declaration order.
 */
it('keeps compiled-manifest order for listeners that declare the same order (stable tie-break)', function () {
    $manifest = new EventListenerManifest([
        new EventListenerDescriptor('Late', 'on', ['x'], 100),
        new EventListenerDescriptor('SecondAtZero', 'on', ['x'], 0),
        new EventListenerDescriptor('FirstAtZero', 'on', ['x'], 0),
        new EventListenerDescriptor('ThirdAtZero', 'on', ['x'], 0),
    ]);

    expect(array_map(static fn ($d) => $d->class, $manifest->ordered()))
        ->toBe(['SecondAtZero', 'FirstAtZero', 'ThirdAtZero', 'Late']);
});
