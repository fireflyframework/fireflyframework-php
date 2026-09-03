<?php

declare(strict_types=1);

use Firefly\Eda\EdaServiceProvider;
use Firefly\Eda\EdaWiringProvider;
use Firefly\Eda\EventPublisher;
use Firefly\Eda\Listener\EventListenerManifest;
use Firefly\Eda\Scanner\EventListenerScanner;
use Firefly\Testing\Fixture\ListenerSpy;

/**
 * ORDERING CONTRACT (end-to-end half), over the REAL boot pipeline: scanner -> manifest -> EventListenerWiringPass
 * -> InMemoryEventBus -> SubscriberRegistry -> handler. Three listeners in tests/OrderedFixtures declare
 * order 30 (Alpha), 10 (Beta) and 20 (Gamma); the scanner emits them in FQCN order (Alpha, Beta, Gamma), so the
 * compiled manifest order and the declared order disagree on every position. Before the fix the wiring pass
 * subscribed in manifest order and the registry fired in subscription order, so this published event ran
 * alpha -> beta -> gamma and #[EventListener(order:)] was decorative. It must run beta -> gamma -> alpha.
 */
it('dispatches #[EventListener]s in declared order, not compiled-manifest order', function () {
    $descriptors = (new EventListenerScanner)->scan([
        'Firefly\\Eda\\Tests\\OrderedFixtures\\' => dirname(__DIR__).'/OrderedFixtures',
    ]);

    // Guard the premise: if the scanner ever stopped emitting FQCN order this test would pass vacuously.
    expect(array_map(static fn ($d) => $d->order, $descriptors))->toBe([30, 10, 20]);

    $context = bootFireflyApp(
        ['firefly' => ['eda' => []]],
        [EdaServiceProvider::class, EdaWiringProvider::class],
        bindings: [
            EventListenerManifest::class => new EventListenerManifest($descriptors),
            ListenerSpy::class => new ListenerSpy,
        ],
    );

    /** @var EventPublisher $bus */
    $bus = $context->get(EventPublisher::class);
    $bus->publish('firefly.events', 'ordered.thing', ['id' => 1]);

    /** @var ListenerSpy $spy */
    $spy = $context->get(ListenerSpy::class);
    expect($spy->seen)->toBe(['beta', 'gamma', 'alpha']);
});
