<?php

declare(strict_types=1);

namespace Firefly\Context\Boot;

use Firefly\Context\Octane\OctaneListener;
use Firefly\Context\Octane\StateResetter;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Laravel\Octane\Events\RequestReceived;

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

        $this->app->booting(static function () use ($kernel): void {
            $kernel->run(...self::definitionStagePhases());
        });

        $this->app->booted(static function () use ($kernel): void {
            $kernel->run(...self::instanceStagePhases());
        });

        $this->registerOctaneListener();
    }

    /**
     * Whether laravel/octane is installed. Overridable ONLY so tests can exercise the "must not
     * fatal when Octane is absent" contract without literally uninstalling a composer package —
     * production subclasses have no reason to override this.
     */
    protected function octaneIsAvailable(): bool
    {
        return class_exists(RequestReceived::class);
    }

    /**
     * Wires OctaneListener to the event dispatcher — but ONLY when Octane is actually present.
     * laravel/octane is a dev dependency, not a runtime requirement (LaraFly's baseline runtime is
     * PHP-FPM, where Octane is never installed at all), so this MUST NOT fatal when it's absent.
     * Guarded a second time by whether OctaneListener is already bound, so registering several
     * FireflyServiceProvider subclasses (one per package) wires the listener exactly once.
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
