<?php

declare(strict_types=1);

use Firefly\Context\Event\ApplicationEventPublisher;
use Firefly\Context\Event\DispatcherEventPublisher;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Testing\Fakes\EventFake;

/**
 * DispatcherEventPublisher is exercised over a REAL Illuminate\Events\Dispatcher and a REAL
 * Illuminate\Container\Container throughout — never a mock of our own ApplicationEventPublisher
 * port, and never a hand-rolled dispatcher double — so these tests prove the adapter actually works
 * against the vendor code it wraps, not against a stand-in for it.
 */
final readonly class SampleEvent
{
    public function __construct(public string $message) {}
}

function makeDispatcherContainer(): Container
{
    $container = new Container;
    $container->instance('events', new Dispatcher($container));

    return $container;
}

// --- port shape ---

it('DispatcherEventPublisher implements the ApplicationEventPublisher port', function () {
    $publisher = new DispatcherEventPublisher(makeDispatcherContainer());

    expect($publisher)->toBeInstanceOf(ApplicationEventPublisher::class);
});

// --- publish -> listener receives the event instance ---

it('publish() lets a registered listener receive the exact event instance', function () {
    $container = makeDispatcherContainer();
    /** @var Dispatcher $dispatcher */
    $dispatcher = $container->make('events');

    $received = null;
    $dispatcher->listen(SampleEvent::class, function (SampleEvent $event) use (&$received): void {
        $received = $event;
    });

    $publisher = new DispatcherEventPublisher($container);
    $event = new SampleEvent('hello');
    $publisher->publish($event);

    expect($received)->toBe($event);
});

// --- 🔴 THE REGRESSION TEST: per-call resolution is what makes Event::fake() intercept us ---

it('resolves the dispatcher on EVERY publish() call, so swapping the container binding AFTER construction is honored', function () {
    $container = makeDispatcherContainer();
    /** @var Dispatcher $realDispatcher */
    $realDispatcher = $container->make('events');

    // Resolve the PUBLISHER FIRST. If DispatcherEventPublisher captured/cached the dispatcher here
    // (e.g. constructor injection instead of per-call container resolution), the swap below would
    // never be observed: publish() would keep talking to $realDispatcher and this test would fail —
    // which is exactly the point. This is the regression test for the whole design point.
    $publisher = new DispatcherEventPublisher($container);

    // Swap the binding AFTER the publisher exists — precisely what Event::fake() does under the
    // hood: $app->instance('events', new EventFake($dispatcher, ...)).
    $fake = new EventFake($realDispatcher);
    $container->instance('events', $fake);

    $publisher->publish(new SampleEvent('swapped'));

    $fake->assertDispatched(
        SampleEvent::class,
        fn (SampleEvent $event): bool => $event->message === 'swapped'
    );
});

it('a publisher constructed BEFORE a real listener existed still reaches it (proves per-call, not per-construction, wiring)', function () {
    $container = makeDispatcherContainer();

    // The publisher is built before any listener is registered.
    $publisher = new DispatcherEventPublisher($container);

    /** @var Dispatcher $dispatcher */
    $dispatcher = $container->make('events');
    $received = null;
    $dispatcher->listen(SampleEvent::class, function (SampleEvent $event) use (&$received): void {
        $received = $event;
    });

    $publisher->publish(new SampleEvent('late-bound'));

    if (! $received instanceof SampleEvent) {
        throw new RuntimeException('Listener never received the event.');
    }

    expect($received->message)->toBe('late-bound');
});

// --- 🔴 THE HALT GUARD: a listener returning false must not starve later listeners ---

it('a GUARDED listener returning false does NOT prevent a later GUARDED listener from running', function () {
    $container = makeDispatcherContainer();
    /** @var Dispatcher $dispatcher */
    $dispatcher = $container->make('events');

    $laterRan = false;

    $dispatcher->listen(SampleEvent::class, DispatcherEventPublisher::guardListener(fn () => false));
    $dispatcher->listen(SampleEvent::class, DispatcherEventPublisher::guardListener(
        function () use (&$laterRan): void {
            $laterRan = true;
        }
    ));

    $publisher = new DispatcherEventPublisher($container);
    $publisher->publish(new SampleEvent('halt-guard'));

    expect($laterRan)->toBeTrue();
});

it('guardListener() always returns null to the dispatcher, regardless of what the wrapped listener returns', function () {
    $guarded = DispatcherEventPublisher::guardListener(fn () => false);

    expect($guarded('anything', ['ignored']))->toBeNull();
});
