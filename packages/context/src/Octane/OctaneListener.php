<?php

declare(strict_types=1);

namespace Firefly\Context\Octane;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\RequestTerminated;
use Laravel\Octane\Events\TaskTerminated;
use Laravel\Octane\Events\TickTerminated;

/**
 * Resets per-request/task/tick container state under Octane.
 *
 * INVARIANT 7 — verified directly against the INSTALLED vendor/laravel/octane/src/Events/ source
 * (laravel/octane v2.17.5) before writing this class: RequestReceived, RequestTerminated,
 * TaskTerminated, and TickTerminated all carry TWO DISTINCT public
 * Illuminate\Foundation\Application properties, `$app` and `$sandbox`. `$app` is the ORIGINAL,
 * long-lived worker application built once per worker in Laravel\Octane\Worker::boot(). `$sandbox`
 * is a `clone $this->app` made FRESH per request/task/tick — see Worker::handle()/
 * handleTask()/handleTick(), each doing `CurrentApplication::set($sandbox = clone $this->app)` —
 * and it is the container Octane actually dispatches through and serves the request/task/tick
 * from (Worker also `$sandbox->flush()`es it afterwards). Resetting `$event->app` instead would be
 * a SILENT NO-OP on a container nothing is ever served from — the bug this class exists to avoid.
 * Every handler below therefore MUST resolve `$event->sandbox`, never `$event->app`.
 *
 * Reset on BOTH RequestReceived and RequestTerminated — NOT because that makes a crash
 * "crash-resilient" (M4 review #6, Minor — the claim this replaces was false; see below), but
 * because each reset serves a genuinely different purpose: RequestReceived strips scoped instances
 * that a *boot-time* resolution left sitting in the long-lived worker `$app` — every sandbox is a
 * fresh `clone $this->app`, so it inherits whatever was resolved onto `$app` before any sandbox
 * existed, and this reset clears that stale state before THIS request runs. RequestTerminated
 * performs the equivalent cleanup for the SAME request's sandbox once it completes normally, for
 * prompt reference release.
 *
 * 🔴 What this does NOT provide: crash resilience for the request that actually dies.
 * `Laravel\Octane\Worker::handle()` dispatches RequestTerminated only on the SUCCESS path (inside
 * its `try`); a request that throws instead dispatches only `WorkerErrorOccurred` — which nothing
 * here subscribes to — and then `finally { $sandbox->flush(); unset($sandbox); }` drops the sandbox
 * outright. That crashed request's own scoped `#[PreDestroy]` callbacks (e.g. an explicit
 * transaction rollback) therefore never run: by the time the NEXT request's RequestReceived resets
 * anything, the dead sandbox (and everything scoped to it) has already been garbage-collected, so
 * `drainScoped()` finds dead `WeakReference`s and silently skips them — the same silent-skip shape
 * `StateResetter`'s own docblock warns `forgetScopedInstances()` produces without a prior drain. The
 * NEXT request is still unpoisoned (Octane's per-request `clone $this->app` guarantees that on its
 * own, independent of this reset), but the CRASHED request's own scoped teardown is simply lost.
 * TaskTerminated and TickTerminated cover concurrent tasks/ticks, which resolve services exactly
 * like requests do and are routinely forgotten.
 */
final class OctaneListener
{
    public function __construct(private readonly StateResetter $resetter) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(RequestReceived::class, [$this, 'handleRequestReceived']);
        $events->listen(RequestTerminated::class, [$this, 'handleRequestTerminated']);
        $events->listen(TaskTerminated::class, [$this, 'handleTaskTerminated']);
        $events->listen(TickTerminated::class, [$this, 'handleTickTerminated']);
    }

    public function handleRequestReceived(RequestReceived $event): void
    {
        $this->resetter->reset($event->sandbox);
    }

    public function handleRequestTerminated(RequestTerminated $event): void
    {
        $this->resetter->reset($event->sandbox);
    }

    public function handleTaskTerminated(TaskTerminated $event): void
    {
        $this->resetter->reset($event->sandbox);
    }

    public function handleTickTerminated(TickTerminated $event): void
    {
        $this->resetter->reset($event->sandbox);
    }
}
