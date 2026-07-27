<?php

declare(strict_types=1);

use Firefly\Container\Scope;
use Firefly\Context\Lifecycle\DisposableBeanRegistry;
use Firefly\Context\Lifecycle\InitDestroyInvoker;
use Firefly\Context\Lifecycle\PreDestroy;
use Firefly\Context\Octane\OctaneListener;
use Firefly\Context\Octane\StateResetter;
use Firefly\Context\Scanner\ContextDescriptor;
use Firefly\Context\Scanner\ContextManifest;
use Firefly\Context\Tests\Support\LaraflyTestCase;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\RequestTerminated;
use Laravel\Octane\Events\TaskTerminated;
use Laravel\Octane\Events\TickTerminated;
use Symfony\Component\HttpFoundation\Response;

/**
 * REAL Octane events fired at a REAL, testbench-booted Illuminate\Foundation\Application — see
 * docs/superpowers/specs/2026-07-15-m4-context-design-decisions.md ("Octane" section) and the M4
 * task brief: "do not ship this on recollection."
 *
 * Verified against the INSTALLED vendor/laravel/octane/src/Events/ source (laravel/octane
 * v2.17.5) before writing this file: RequestReceived, RequestTerminated, TaskTerminated, and
 * TickTerminated ALL carry two DISTINCT public Illuminate\Foundation\Application properties —
 * `$app` (the original, long-lived worker application) and `$sandbox` (a `clone $this->app` made
 * FRESH per request/task/tick in Laravel\Octane\Worker — see Worker::handle()/handleTask()/
 * handleTick(), each doing `CurrentApplication::set($sandbox = clone $this->app)`). `$sandbox` is
 * the container Octane actually dispatches the request/task/tick through (Worker also calls
 * `$sandbox->flush()` at the end of each). Resetting `$event->app` instead would silently reset a
 * container NOTHING is being served from — INVARIANT 7.
 */
final class ScopedOctaneProbe
{
    public bool $destroyed = false;

    #[PreDestroy]
    public function markDestroyed(): void
    {
        $this->destroyed = true;
    }
}

/**
 * Wires an independent scoped #[PreDestroy] bean + DisposableBeanRegistry onto ONE container.
 * Called separately for $app and for $sandbox so their tracked state is provably independent —
 * the whole point of the regression tests below.
 *
 * @return array{registry: DisposableBeanRegistry, probe: ScopedOctaneProbe}
 */
function wireScopedOctaneProbe(Container $container): array
{
    $manifest = new ContextManifest([
        new ContextDescriptor(class: ScopedOctaneProbe::class, preDestroy: ['markDestroyed']),
    ]);
    $registry = new DisposableBeanRegistry(new InitDestroyInvoker($container, $manifest));
    $container->instance(DisposableBeanRegistry::class, $registry);

    $container->scoped(ScopedOctaneProbe::class, static fn (): ScopedOctaneProbe => new ScopedOctaneProbe);

    /** @var ScopedOctaneProbe $probe */
    $probe = $container->make(ScopedOctaneProbe::class);
    $registry->register($probe, ScopedOctaneProbe::class, Scope::Scoped);

    return ['registry' => $registry, 'probe' => $probe];
}

uses(LaraflyTestCase::class);

// --- the regression test: this is the whole point of this file ---
//
// Each case builds a REAL Octane event whose $app and $sandbox are two DISTINCT, independently
// wired containers. If OctaneListener targeted $event->app instead of $event->sandbox, the
// "sandbox reset" assertions below would fail (nothing on the sandbox would ever be touched) and
// the "app untouched" assertions would ALSO fail (the app's probe would be destroyed instead) —
// either half alone already catches the bug; asserting both makes it airtight.
it(
    'resets ONLY $event->sandbox — the container actually serving the request/task/tick — never $event->app',
    function (string $handlerMethod, Closure $makeEvent): void {
        /** @var LaraflyTestCase $this */
        $app = $this->app();
        $sandbox = clone $app;

        $appState = wireScopedOctaneProbe($app);
        $sandboxState = wireScopedOctaneProbe($sandbox);

        $listener = new OctaneListener(new StateResetter);
        $listener->{$handlerMethod}($makeEvent($app, $sandbox));

        // The SANDBOX must be reset: its #[PreDestroy] ran, and forgetScopedInstances() ran (a
        // fresh make() rebuilds a new instance).
        expect($sandboxState['probe']->destroyed)->toBeTrue()
            ->and($sandbox->make(ScopedOctaneProbe::class))->not->toBe($sandboxState['probe']);

        // The ORIGINAL app must be completely untouched.
        expect($appState['probe']->destroyed)->toBeFalse()
            ->and($app->make(ScopedOctaneProbe::class))->toBe($appState['probe']);
    }
)->with([
    'RequestReceived' => [
        'handleRequestReceived',
        static fn (Application $app, Application $sandbox): RequestReceived => new RequestReceived($app, $sandbox, Request::create('/')),
    ],
    'RequestTerminated' => [
        'handleRequestTerminated',
        static fn (Application $app, Application $sandbox): RequestTerminated => new RequestTerminated($app, $sandbox, Request::create('/'), new Response),
    ],
    'TaskTerminated' => [
        'handleTaskTerminated',
        static fn (Application $app, Application $sandbox): TaskTerminated => new TaskTerminated($app, $sandbox, ['task' => 'data'], 'result'),
    ],
    'TickTerminated' => [
        'handleTickTerminated',
        static fn (Application $app, Application $sandbox): TickTerminated => new TickTerminated($app, $sandbox),
    ],
]);

// --- subscribe() wiring: registering the listener actually connects each real Octane event class ---

it(
    'subscribe() wires every Octane lifecycle event to its reset handler on a real Dispatcher',
    function (Closure $makeEvent): void {
        /** @var LaraflyTestCase $this */
        $app = $this->app();
        $sandbox = clone $app;

        wireScopedOctaneProbe($app);
        $sandboxState = wireScopedOctaneProbe($sandbox);

        $dispatcher = new Dispatcher;
        (new OctaneListener(new StateResetter))->subscribe($dispatcher);

        $event = $makeEvent($app, $sandbox);
        if (! $event instanceof RequestReceived
            && ! $event instanceof RequestTerminated
            && ! $event instanceof TaskTerminated
            && ! $event instanceof TickTerminated) {
            throw new LogicException('Dataset factory must build one of the four Octane lifecycle events.');
        }

        $dispatcher->dispatch($event);

        expect($sandboxState['probe']->destroyed)->toBeTrue();
    }
)->with([
    'RequestReceived' => [
        static fn (Application $app, Application $sandbox): RequestReceived => new RequestReceived($app, $sandbox, Request::create('/')),
    ],
    'RequestTerminated' => [
        static fn (Application $app, Application $sandbox): RequestTerminated => new RequestTerminated($app, $sandbox, Request::create('/'), new Response),
    ],
    'TaskTerminated' => [
        static fn (Application $app, Application $sandbox): TaskTerminated => new TaskTerminated($app, $sandbox, ['task' => 'data'], 'result'),
    ],
    'TickTerminated' => [
        static fn (Application $app, Application $sandbox): TickTerminated => new TickTerminated($app, $sandbox),
    ],
]);
