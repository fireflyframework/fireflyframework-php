<?php

declare(strict_types=1);

namespace Firefly\Context\Event;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * The ApplicationEventPublisher adapter: a thin port over illuminate/events. Laravel's dispatcher
 * does the actual work (listener storage, matching, invocation) — this class only translates
 * publish(object) into the one Illuminate call that means the same thing.
 *
 * 🔴 CRITICAL: the Dispatcher is resolved from the container FRESH on every publish() call. It is
 * NEVER taken as a constructor dependency and NEVER cached on $this. This is not a style preference:
 * Event::fake() works by swapping the 'events' container binding — $app->instance('events', new
 * EventFake($dispatcher)) — AFTER the application (and this publisher) already exist. A publisher
 * that captured the dispatcher at construction time would go on holding the REAL dispatcher forever
 * and publish straight past the fake. Every Event::fake()-based test assertion in firefly/testing
 * (M13) would then silently assert nothing, in a way that looks like passing code. Resolving per
 * call is what makes Event::fake() (or any swapped 'events' binding) intercept us — see
 * ApplicationEventPublisherTest's regression test, which constructs the publisher BEFORE swapping
 * the binding specifically to catch a future "optimization" that reintroduces caching.
 *
 * 🔴 THE HALT GUARD — empirically verified BEFORE this class was written (see
 * DispatcherHaltCharacterizationTest, and invokeListeners() in
 * vendor/laravel/framework/src/Illuminate/Events/Dispatcher.php): Illuminate's dispatch loop breaks
 * the instant ANY listener returns EXACTLY `false` —
 *
 *     if ($response === false) { break; }
 *
 * — and this is UNCONDITIONAL: it fires regardless of the $halt flag passed to dispatch()/until()
 * ($halt only gates a separate, earlier "return on first non-null response" check). Firefly's
 * application events are notifications, not filters — a listener whose last expression happens to
 * evaluate falsy (an easy, silent accident: `return $repository->delete($id);` where delete()
 * returns bool) must never be able to silently starve every listener registered after it.
 *
 * publish() itself still calls Dispatcher::dispatch() UNCHANGED and UNCONDITIONALLY — that call is
 * exactly what Event::fake() intercepts, and bypassing it (e.g. by manually looping over
 * getListeners()) would defeat fake-mode interception even more thoroughly than the bug this guard
 * fixes. The guard is therefore applied at the LISTENER, not the dispatch call: self::guardListener()
 * wraps a raw listener callable so it always returns null to the dispatcher, no matter what the
 * wrapped callable itself returns. Any code that registers a listener for a publish()-ed event
 * (this milestone's later RegisterEventListenersPass, wiring #[AsEventListener] methods) MUST route
 * the raw listener through this method for the notification guarantee to hold.
 */
final class DispatcherEventPublisher implements ApplicationEventPublisher
{
    public function __construct(private readonly Container $container) {}

    public function publish(object $event): void
    {
        // Resolved EVERY call — see class docblock. Do not hoist this into the constructor.
        /** @var Dispatcher $dispatcher */
        $dispatcher = $this->container->make('events');

        $dispatcher->dispatch($event);
    }

    /**
     * Wrap a raw listener so a literal `false` return can never reach Illuminate's dispatch loop —
     * see class docblock for the empirically-confirmed bug this defeats.
     */
    public static function guardListener(callable $listener): Closure
    {
        return static function (mixed ...$arguments) use ($listener): void {
            $listener(...$arguments);
        };
    }
}
