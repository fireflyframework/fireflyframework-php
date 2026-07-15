<?php

declare(strict_types=1);

namespace Firefly\Context\Boot;

use Firefly\Context\Event\ApplicationEventPublisher;
use Firefly\Context\Event\DispatcherEventPublisher;
use Firefly\Context\Octane\OctaneListener;
use Firefly\Context\Octane\StateResetter;
use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\WorkerStopping;

/**
 * Base provider every application and later-milestone package extends to plug into the
 * FireflyKernel boot pipeline. Subclasses override passes() to CONTRIBUTE BootPass instances; the
 * KERNEL — never provider registration order — decides WHEN each one actually runs. Assumes a
 * FireflyKernel is already bound in the container (a future bootstrap layer's job, NOT this
 * class's — see the M4 task brief's scope note); this class only ever ADDS to it.
 *
 * INVARIANT 10 — the kernel owns ordering; providers only contribute passes. Laravel's
 * registerConfiguredProviders() registers auto-discovered (package) providers BEFORE application
 * providers — partitioning [Illuminate…] then splicing the PackageManifest in at index 1 — the
 * EXACT INVERSE of what this boot pipeline needs (an application's own configuration must be free
 * to run relative to a package's auto-configuration on the KERNEL's terms, never on the accident
 * of which provider Laravel happened to instantiate first). Deriving pass order from provider
 * registration order would make boot order silently depend on that accident — precisely the class
 * of bug FireflyKernel exists to eliminate. This is exactly the "harmless simplification" a future
 * maintainer would reach for; it is not harmless.
 *
 * The booting()/booted() split below (verified: Illuminate\Foundation\Application::boot() fires
 * EVERY bootingCallback before calling ANY provider's own boot(), and EVERY bootedCallback only
 * after ALL of them have run) is why DEFINITION-stage phases (100–650: pure data, no container
 * writes — see BootPhase's own docblock) run from booting(), and INSTANCE-stage phases (700–1200)
 * run from booted(): it buys the guarantee that EVERY provider's own boot() method — including a
 * subclass that overrides boot() for its own purposes — sees a fully-wired Firefly container,
 * regardless of which provider Laravel happens to construct/register first.
 *
 * FireflyKernel::run() is idempotent PER PHASE (see its own docblock), which is what makes it safe
 * for EVERY FireflyServiceProvider subclass to register the SAME two closures below: only the
 * FIRST booting()/booted() callback to fire actually runs each phase's passes — every subsequent
 * one, from every other subclass, becomes a no-op. This is what lets many independent
 * FireflyServiceProvider subclasses (one per Firefly package) safely coexist without any of them
 * needing to know about the others, or about registration order.
 */
abstract class FireflyServiceProvider extends ServiceProvider
{
    /**
     * BootPass instances this subclass contributes to the shared FireflyKernel. The KERNEL —
     * never this method's caller — decides when each one runs; see the class docblock
     * (INVARIANT 10). Default: none.
     *
     * @return list<BootPass>
     */
    public function passes(): array
    {
        return [];
    }

    public function register(): void
    {
        $kernel = $this->app->make(FireflyKernel::class);

        foreach ($this->passes() as $pass) {
            $kernel->addPass($pass);
        }

        $this->bindApplicationEventPublisher();

        $this->app->booting(static function () use ($kernel): void {
            $kernel->run(...self::definitionStagePhases());
        });

        $this->app->booted(function () use ($kernel): void {
            $kernel->run(...self::instanceStagePhases());

            $this->bootApplicationContext($kernel);
        });

        $this->registerOctaneListener();
    }

    /**
     * Binds the ApplicationEventPublisher PORT to the shipped DispatcherEventPublisher ADAPTER — the
     * documented primary way application code publishes/observes events (docs/modules/context.md's
     * Events section). Without this, a `#[Component]` constructor-injecting the documented port
     * cannot resolve, and because EagerSingletonsPass (phase 900) resolves every non-`#[Lazy]`
     * singleton eagerly, that failure aborts boot rather than merely failing at first use.
     *
     * DELIBERATELY bound HERE, as an explicit container write, rather than by giving
     * DispatcherEventPublisher a `#[Component]` attribute: a framework adapter class living in
     * packages/context/src/Event/ is never on any application's scanned PSR-4 root, so `#[Component]`
     * on it would never actually be discovered by ComponentScanner — and even if it somehow were,
     * turning that discovery into a container binding depends on the phase 200/500
     * AutoConfigDiscovery/AutoConfigurations bootstrap-layer seam this milestone deliberately defers
     * (see docs/modules/context.md). Attaching `#[Component]` today would just be a SECOND instance
     * of the exact "authored but never consumed" shape this fix closes. An explicit binding in
     * framework glue code is the same choice `Firefly\Container\Registrar\ContainerRegistrar::
     * registerValueSupport()` already makes for `ValueResolver` — a foundational port bound by the
     * framework itself, not discovered by scanning the framework's own classes.
     *
     * Guarded by bound(), same pattern as registerOctaneListener(): several FireflyServiceProvider
     * subclasses (one per Firefly package) may all call register(), but only the first one actually
     * binds it.
     *
     * Bound as a singleton ADAPTER INSTANCE, never a cached Dispatcher: DispatcherEventPublisher
     * itself still resolves the 'events' binding FRESH on every publish() call (see its own
     * docblock) — this binding only avoids re-constructing the thin wrapper object on every
     * resolution, and must NEVER be changed to inject/cache the Dispatcher, or Event::fake()
     * interception breaks.
     */
    private function bindApplicationEventPublisher(): void
    {
        if ($this->app->bound(ApplicationEventPublisher::class)) {
            return;
        }

        $this->app->singleton(
            ApplicationEventPublisher::class,
            static fn (Container $container): ApplicationEventPublisher => new DispatcherEventPublisher($container),
        );
    }

    /**
     * Runs the kernel to completion and binds the resulting ApplicationContext as a singleton, then
     * wires ApplicationContext::close() to the runtime's ACTUAL shutdown hook — which hook that is
     * DIFFERS BY RUNTIME. Treating one hook as "application shutdown" everywhere is exactly the bug
     * an earlier version of this docblock caused (M4 review #3, Critical): it cited Octane's own
     * call path as *proof* `$app->terminating()` meant shutdown, when Octane calling `terminate()`
     * on every request is proof of the opposite.
     *
     * `$kernel->boot()` is safe to call unconditionally here: it runs every BootPhase, but
     * FireflyKernel::run() is idempotent per phase (see its own docblock), so every phase already
     * completed by the booting()/booted() calls above — from THIS provider or any other
     * FireflyServiceProvider subclass — is skipped, and boot() proceeds straight to assembling the
     * ApplicationContext.
     *
     * Guarded by bound(), same pattern as registerOctaneListener()/bindApplicationEventPublisher():
     * every FireflyServiceProvider subclass's booted() callback reaches this method, but only the
     * FIRST one to run it builds+binds the context and registers the shutdown hook — every
     * subsequent call is a no-op. This is safe because, by the time ANY booted() callback fires,
     * every provider's register() has already run (Laravel calls every provider's register() before
     * any provider's boot()/booted() callback), so every pass from every FireflyServiceProvider
     * subclass is already contributed to this SAME shared kernel — kernel->boot() here always sees
     * the complete, final pass list regardless of which subclass's callback reaches this line first.
     *
     * THE TWO RUNTIMES, VERIFIED AGAINST INSTALLED SOURCE (laravel/framework v13.20.0,
     * laravel/octane v2.17.5):
     *
     * - PHP-FPM (this package's baseline runtime — a FRESH `Illuminate\Foundation\Application` per
     *   request): `Illuminate\Foundation\Http\Kernel::terminate()` calls `$this->app->terminate()`
     *   once per request, via public/index.php's `$kernel->terminate($request, $response)`. Because
     *   the application itself is fresh per request, "this request ended" and "this application
     *   instance is shutting down" are THE SAME EVENT — `$app->terminating(...)` is correct here.
     * - Octane: `Laravel\Octane\Worker::handle()` clones the long-lived WORKER application into a
     *   per-request `$sandbox` (`CurrentApplication::set($sandbox = clone $this->app)`), and
     *   `Laravel\Octane\ApplicationGateway::terminate()` then calls `Application::terminate()` on
     *   THAT SANDBOX at the end of EVERY request. A PHP clone copies the worker's
     *   `$terminatingCallbacks` array by value, so a callback registered via `$app->terminating()`
     *   would fire once per REQUEST, not once per WORKER — closing every singleton's
     *   `#[PreDestroy]`/`Lifecycle::stop()` after the worker's first request and silently serving
     *   requests 2..N against disconnected/stopped objects (close() is idempotent, so this never
     *   errors or repeats; it just goes quiet). So under Octane, `terminating()` MUST NOT be used —
     *   `close()` is wired to `Laravel\Octane\Events\WorkerStopping` instead, which
     *   `Laravel\Octane\Worker` dispatches exactly ONCE per worker, at real worker shutdown (see
     *   `vendor/laravel/octane/src/Worker.php`).
     *
     * `$event->app` vs `$event->sandbox`, verified directly against the installed source
     * (`vendor/laravel/octane/src/Events/WorkerStopping.php`): WorkerStopping's constructor is
     * `__construct(public Application $app)` — there is NO `$sandbox` property on this event, unlike
     * RequestReceived/RequestTerminated/TaskTerminated/TickTerminated (see OctaneListener's own
     * docblock, invariant 7). There is no per-worker-shutdown clone to speak of, so `$event->app`
     * IS the worker and is the only, correct target — this is deliberately NOT a violation of
     * invariant 7, which governs only the per-request/task/tick events where Octane actually serves
     * traffic through a cloned sandbox and the original `$app` is never the one in use.
     *
     * THE OCTANE BRANCH IS SELECTED BY octaneIsAvailable() — see that method's own docblock (M4
     * review #4, Important): the check is package presence AND the `LARAVEL_OCTANE` runtime marker,
     * NOT package presence alone, precisely so that `composer require laravel/octane` (the package's
     * only documented install path, which puts the class on every process's classpath — `queue:work`,
     * `schedule:run`, artisan commands, tests, and any FPM-served route in a hybrid deploy included)
     * does not silently route every one of those non-worker processes into the WorkerStopping branch,
     * where `close()` would never run because nothing in that process ever dispatches
     * `WorkerStopping`. Guarded the same way as registerOctaneListener() so this MUST NOT fatal when
     * Octane is absent either.
     */
    private function bootApplicationContext(FireflyKernel $kernel): void
    {
        if ($this->app->bound(ApplicationContext::class)) {
            return;
        }

        $context = $kernel->boot();

        $this->app->instance(ApplicationContext::class, $context);

        if ($this->octaneIsAvailable()) {
            // $event->app, not $event->sandbox — see the docblock above: WorkerStopping carries no
            // sandbox at all, so $event->app IS the worker being stopped.
            $this->app->make(Dispatcher::class)->listen(
                WorkerStopping::class,
                static function () use ($context): void {
                    $context->close();
                },
            );
        } else {
            $this->app->terminating(static function () use ($context): void {
                $context->close();
            });
        }
    }

    /**
     * Whether this process is ACTUALLY RUNNING INSIDE an Octane worker right now — not merely
     * whether the laravel/octane PACKAGE is installed. Overridable ONLY so tests can exercise the
     * "must not fatal when Octane is absent" contract without literally uninstalling a composer
     * package — production subclasses have no reason to override this.
     *
     * REGRESSION (M4 review #4, Important): an earlier version of this method returned
     * `class_exists(RequestReceived::class)` alone. `composer require laravel/octane` (non-dev) is
     * the package's only documented install path, so in a real Octane app the class exists on the
     * classpath of EVERY process — not just the worker — including `php artisan queue:work`,
     * `schedule:run`, plain migrations/console commands, this package's own test suite, and any
     * FPM-served route in a hybrid deploy. Gating on presence alone therefore routed every one of
     * those non-worker processes into the WorkerStopping branch below, where `close()` never ran
     * (nothing in those processes ever dispatches `WorkerStopping`) — silently skipping
     * `#[PreDestroy]`/`Lifecycle::stop()` in every one of them.
     *
     * The fix adds the RUNTIME half: `$_SERVER['LARAVEL_OCTANE']`, a process environment variable
     * injected by Octane's OWN start commands into the server process BEFORE PHP even starts —
     * verified against the installed source (laravel/octane v2.17.5): `Commands/StartSwooleCommand.
     * php:89`, `Commands/StartRoadRunnerCommand.php:101`, and `Commands/StartFrankenPhpCommand.php:96`
     * all set `'LARAVEL_OCTANE' => 1` in the spawned server `Process`'s env. Because it is set before
     * PHP executes, there is no "not yet set" window at `register()` time to worry about — it is
     * simply already there, or it never will be.
     *
     * This is NOT a bespoke signal invented for this check: Laravel's OWN framework reads this exact
     * marker, via this exact `isset($_SERVER[...])` idiom, to make this exact decision ("is Octane
     * actually running right now"), from a service provider's `boot()`-adjacent code — verified
     * against the installed source (laravel/framework v13.20.0):
     * `Illuminate/Foundation/Exceptions/Renderer/Listener.php:37` and
     * `Illuminate/Routing/ResponseFactory.php:201`. Do not "simplify" this back to
     * `class_exists()` alone — that is precisely the regression this docblock exists to prevent.
     */
    protected function octaneIsAvailable(): bool
    {
        return isset($_SERVER['LARAVEL_OCTANE']) && class_exists(RequestReceived::class);
    }

    /**
     * Wires OctaneListener to the event dispatcher — but ONLY when octaneIsAvailable() is true, i.e.
     * this process is actually running inside an Octane worker (see that method's own docblock).
     * That is also the ONLY runtime that ever dispatches the events this listener subscribes to
     * (`RequestReceived`/`RequestTerminated`/`TaskTerminated`/`TickTerminated` are dispatched
     * exclusively by `Laravel\Octane\Worker`), so requiring the runtime marker here — not merely
     * package presence — changes nothing observable: those events could never have fired in a
     * non-worker process anyway, worker or not. laravel/octane is a dev dependency, not a runtime
     * requirement (LaraFly's baseline runtime is PHP-FPM, where Octane is never installed at all), so
     * this MUST NOT fatal when it's absent. Guarded a second time by whether OctaneListener is
     * already bound, so registering several FireflyServiceProvider subclasses (one per package) wires
     * the listener exactly once.
     */
    private function registerOctaneListener(): void
    {
        if (! $this->octaneIsAvailable()) {
            return;
        }

        if ($this->app->bound(OctaneListener::class)) {
            return;
        }

        $listener = new OctaneListener(new StateResetter);
        $this->app->instance(OctaneListener::class, $listener);
        $listener->subscribe($this->app->make(Dispatcher::class));
    }

    /**
     * Phases 100–650 (see BootPhase): pure BeanDefinitionRegistry data, no container writes.
     *
     * Built by APPENDING to a fresh array — never array_filter()+array_values() — so the
     * `list<BootPhase>` contract holds by construction, not merely because BootPhase's cases
     * happen, today, to already declare a contiguous run of low-to-high ordinals for this half of
     * the pipeline. array_filter() preserves the SOURCE keys of whatever it keeps; keeping a
     * PREFIX of BootPhase::cases() (as this filter does today) happens to leave 0-based, contiguous
     * keys behind on its own, with no array_values() needed — which is exactly why a later
     * maintainer could "simplify" this by dropping array_values() (as an earlier version of this
     * method did) without ANY test or static-analysis tool catching it, right up until a future
     * milestone declares a new case with a low ordinal at the END of the enum (BootPhase's ordinals
     * are deliberately gapped for exactly that kind of insertion), at which point array_filter()
     * would silently hand back non-contiguous keys and the declared `list` return type would quietly
     * become false. Appending element-by-element cannot have that failure mode, for any future
     * ordering of BootPhase's cases whatsoever.
     *
     * @return list<BootPhase>
     */
    private static function definitionStagePhases(): array
    {
        $phases = [];
        foreach (BootPhase::cases() as $phase) {
            if ($phase->value <= BootPhase::FlushDefinitions->value) {
                $phases[] = $phase;
            }
        }

        return $phases;
    }

    /**
     * Phases 700–1200 (see BootPhase): operate on resolved instances. See definitionStagePhases()'s
     * docblock for why this is built the same append-only way rather than via array_filter().
     *
     * @return list<BootPhase>
     */
    private static function instanceStagePhases(): array
    {
        $phases = [];
        foreach (BootPhase::cases() as $phase) {
            if ($phase->value > BootPhase::FlushDefinitions->value) {
                $phases[] = $phase;
            }
        }

        return $phases;
    }
}
