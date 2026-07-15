<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;

/**
 * EMPIRICAL CHARACTERIZATION of an UNDOCUMENTED Illuminate\Events\Dispatcher behavior that
 * DispatcherEventPublisher's halt guard exists to defeat. Written and run FIRST, against a REAL
 * Dispatcher over a REAL Container — no mocks — before any guard code was written, per the M4 plan's
 * Task 10 and docs/superpowers/specs/2026-07-15-m4-context-design-decisions.md (fact 10).
 *
 * FINDING (confirmed both by reading invokeListeners() in
 * vendor/laravel/framework/src/Illuminate/Events/Dispatcher.php (lines ~314-343), and by the
 * assertions below): the listener loop breaks the instant ANY listener returns EXACTLY `false` —
 *
 *     if ($response === false) { break; }
 *
 * — and this check is UNCONDITIONAL: it runs regardless of the `$halt` argument passed to
 * dispatch()/until(). `$halt` only controls a SEPARATE, earlier check ("if ($halt && ...) return
 * $response;" — short-circuit on the first non-null response). The two are independent switches:
 * turning $halt off does NOT protect later listeners from a `false`-returning earlier one.
 *
 * This is why DispatcherEventPublisher ships a listener guard (see its class docblock): our events
 * are notifications, not filters, so a listener that happens to end in a falsy expression (an easy,
 * silent accident) must never be able to starve every listener registered after it.
 *
 * If a future Laravel release changes this, THIS test fails and tells us here — not in a production
 * incident where a listener 12 lines away mysteriously never runs.
 */
final class HaltProbeEvent {}

it('BUG (confirmed): an unwrapped listener returning false silently stops every later listener, even with $halt=false', function () {
    $dispatcher = new Dispatcher(new Container);
    $laterRan = false;

    $dispatcher->listen(HaltProbeEvent::class, fn () => false);
    $dispatcher->listen(HaltProbeEvent::class, function () use (&$laterRan): void {
        $laterRan = true;
    });

    $dispatcher->dispatch(new HaltProbeEvent, [], false);

    expect($laterRan)->toBeFalse();
});

it('BUG (confirmed): until() is affected too — $halt=true changes only the non-null short-circuit, not the false break', function () {
    $dispatcher = new Dispatcher(new Container);
    $laterRan = false;

    $dispatcher->listen(HaltProbeEvent::class, fn () => false);
    $dispatcher->listen(HaltProbeEvent::class, function () use (&$laterRan): void {
        $laterRan = true;
    });

    $dispatcher->until(new HaltProbeEvent);

    expect($laterRan)->toBeFalse();
});

it('control case: a listener returning null (the normal case) does NOT stop later listeners', function () {
    $dispatcher = new Dispatcher(new Container);
    $laterRan = false;

    $dispatcher->listen(HaltProbeEvent::class, function (): void {
        // returns null, not false
    });
    $dispatcher->listen(HaltProbeEvent::class, function () use (&$laterRan): void {
        $laterRan = true;
    });

    $dispatcher->dispatch(new HaltProbeEvent);

    expect($laterRan)->toBeTrue();
});
