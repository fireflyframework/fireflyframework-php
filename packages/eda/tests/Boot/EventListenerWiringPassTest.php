<?php

declare(strict_types=1);

use Firefly\Eda\DeadLetter\DeadLetterStore;
use Firefly\Eda\EdaServiceProvider;
use Firefly\Eda\EdaWiringProvider;
use Firefly\Eda\EventPublisher;
use Firefly\Eda\Listener\EventListenerManifest;
use Firefly\Eda\Scanner\EventListenerScanner;
use Firefly\Testing\Fixture\ListenerSpy;

/**
 * REAL-PROVIDER wiring: the shipped EdaServiceProvider (candidacy) + EdaWiringProvider (passes) + bootstrap
 * assemble over the live boot pipeline. A one-row manifest (compiled inline by the real scanner over the fixtures)
 * must be subscribed onto the auto-config-bound InMemoryEventBus at boot, so a later publish reaches the listener.
 */
it('subscribes compiled #[EventListener]s onto the bus at boot (in-memory provider)', function () {
    // Compile the fixtures inline (exactly what firefly:cache emits, M15) and bind the manifest + a shared ListenerSpy.
    $descriptors = (new EventListenerScanner)->scan(['Firefly\\Eda\\Tests\\Fixtures\\' => dirname(__DIR__).'/Fixtures']);

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
    $bus->publish('firefly.events', 'order.placed', ['id' => 1]);

    /** @var ListenerSpy $spy */
    $spy = $context->get(ListenerSpy::class);
    expect($spy->seen)->toBe(['order.placed']); // fail.* listener never matched
});

/**
 * The wiring layer is where the "policy-free" bus gets its retry/DLQ policy: the pass wraps each listener in a
 * RetryingEventHandler before subscribe(). This pins that wrap AT the wiring layer — a throwing listener wired at
 * boot must be caught and dead-lettered (not escape publish()), which only holds if the wrap is applied. Drop the
 * RetryingEventHandler::wrap in the pass and this fails (the RuntimeException escapes / the DLQ stays empty).
 */
it('wraps each wired listener in retry/DLQ: a throwing listener is dead-lettered, not escaped', function () {
    $descriptors = (new EventListenerScanner)->scan(['Firefly\\Eda\\Tests\\Fixtures\\' => dirname(__DIR__).'/Fixtures']);

    // config: retries default 0 -> first failure DLQs
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
    $bus->publish('firefly.events', 'fail.boom', ['id' => 9]); // matches FailingListener 'fail.*' -> throws

    /** @var DeadLetterStore $dlq */
    $dlq = $context->get(DeadLetterStore::class);
    $entries = $dlq->all();
    expect($entries)->toHaveCount(1)
        ->and($entries[0]->exceptionMessage)->toBe('listener boom');
});
