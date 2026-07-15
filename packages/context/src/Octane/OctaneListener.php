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
 * Reset on BOTH RequestReceived (crash-resilient: a request dying mid-flight cannot poison the
 * next one, since the NEXT request's RequestReceived resets before that next request runs) AND
 * RequestTerminated (prompt reference release once a request completes normally). TaskTerminated
 * and TickTerminated cover concurrent tasks/ticks, which resolve services exactly like requests do
 * and are routinely forgotten.
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
