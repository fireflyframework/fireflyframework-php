<?php

declare(strict_types=1);

namespace Firefly\Context\Lifecycle;

use Firefly\Container\Scope;
use WeakReference;

/**
 * Tracks beans that need their #[PreDestroy] method(s) invoked, so a later boot pass can drain
 * them at the right time.
 *
 * Holds WEAK references, never strong ones: a *contextual* build (Illuminate's
 * needsContextualBuild — see KernelContractTest / the M4 design decisions doc) runs
 * BeanPostProcessor extenders but is NEVER cached by the container. A strong reference here
 * would pin every such throwaway instance in memory for the registry's lifetime — effectively
 * forever under Octane, since the registry itself lives for the whole worker process. A ref
 * whose get() returns null (already garbage-collected) is silently skipped on drain.
 *
 * Singleton and Scoped beans are tracked in SEPARATE ledgers because they drain at different
 * times: Singletons at context close; Scoped beans PER REQUEST. Scoped #[PreDestroy] callbacks
 * MUST run via drainScoped() BEFORE Illuminate\Container\Container::forgetScopedInstances(),
 * which has no destruction callback of its own and would otherwise silently drop anything not
 * drained first — a later Octane dispatch is responsible for calling drainScoped() in that
 * order. Scope::Transient beans are never tracked at all: prototype-scoped beans are not
 * lifecycle-managed (Spring parity).
 *
 * Only beans whose DECLARED class actually has a #[PreDestroy] method are tracked at all — this
 * is what makes the registry "Tracks beans needing #[PreDestroy]" rather than every bean.
 *
 * Both ledgers destroy in REVERSE registration order — dependents (registered later) are torn
 * down before the dependencies (registered earlier) they may still be using.
 *
 * 🔴 KNOWN GAP, OCTANE ONLY (M4 review #5, Important; completed by M4 review #6, Important 2 —
 * disclosed, not fixed; see docs/modules/context.md's Lifecycle and Octane sections for the
 * user-facing version, and `LazySingletonOctaneIdentityTest` for the measured, executable proof):
 * a `#[Lazy]` `Scope::Singleton` first resolved INSIDE an Octane request is not merely missing its
 * `#[PreDestroy]` — it is not a singleton AT ALL under Octane. It is rebuilt from scratch on EVERY
 * request that resolves it. `EagerSingletonsPass` builds a non-#[Lazy] singleton into the WORKER's
 * own container at boot, before any request is served, and Octane's per-request `clone $this->app`
 * then shares that SAME object by reference into every sandbox — genuinely once, for the worker's
 * whole life (true for every singleton under PHP-FPM too: no worker/sandbox split exists there at
 * all). A `#[Lazy]` singleton skips that eager step by design, so its first resolution is deferred
 * until something actually asks for it — and under Octane, that first ask almost always happens
 * INSIDE a request, building it into that request's SANDBOX (`Laravel\Octane\Worker::handle()`'s
 * `clone $this->app`), never into the worker. The sandbox's own container cache is a NEW, empty one
 * every request, so this repeats on every request that resolves it: NOT built once, but once PER
 * REQUEST — silently, with no error, defeating the entire reason `#[Lazy]` + `Scope::Singleton` is
 * chosen together (build once, but not at boot).
 *
 * Losing `#[PreDestroy]` is a COROLLARY of that identity loss, not a separate failure: the
 * singleton ledger's WeakReference is only safe to drain at `drainSingletons()` time (context
 * close, once per worker) if something ELSE holds a STRONG reference to the bean for the whole
 * worker's life — true for the worker-resolved/PHP-FPM cases above, NOT true for a `#[Lazy]`
 * singleton resolved into a sandbox, whose own container state is the only strong reference.
 * `register()` here still runs (via the same `Container::extend()` seam every bean passes through)
 * and records a WeakReference exactly as always, but once that sandbox is discarded
 * (`$sandbox->flush()`, end of request) nothing keeps the bean alive, and it is silently
 * garbage-collected long before `drainSingletons()` ever runs — so its `#[PreDestroy]` never fires
 * either.
 *
 * A genuine fix would need to distinguish "this resolution will become the resolving container's
 * own durable shared instance" from "this is a throwaway `needsContextualBuild` resolution" (the
 * exact case the WeakReference above exists to tolerate) — a distinction Illuminate's container
 * does not expose at the `extend()` seam this package is committed to as the ONLY BeanPostProcessor
 * extension point (`Container.php:940-961`: the extender runs strictly pre-cache and receives only
 * `($object, $this)`, verified directly against the installed source before writing this). CANDIDATE
 * FOR A LATER MILESTONE, not attempted now: `fireResolvingCallbacks()` runs POST-cache but still
 * IN-BAND, inside the same `resolve()` call (`Container.php:952-954`) — so `Container::afterResolving()`
 * callbacks see the object AFTER it is already cached, in the same call that built it, without
 * needing a new per-request enumeration/promotion pass hooked to `RequestTerminated`/
 * `TaskTerminated`/`TickTerminated`. That would not violate invariant 1 (which governs BeanPostProcessor
 * *substitution* via `extend()`/`fireCallbackArray`, not lifecycle *observation*). It is NOT obviously
 * correct as-is (`resolved()` is also true for a contextual rebuild of an already-resolved abstract,
 * which must NOT be promoted), so it remains a candidate to investigate, not a narrow, obviously-safe
 * change to make now — exactly the kind of reach that introduced two of this milestone's own bugs.
 * Disclosed honestly instead: under Octane, `#[Lazy]` + `Scope::Singleton` is rebuilt every request
 * (not a singleton) and its `#[PreDestroy]` never runs. Treat that combination as effectively
 * unsupported under Octane until a future milestone closes this gap.
 */
final class DisposableBeanRegistry
{
    /** @var list<array{ref: WeakReference<object>, declaredClass: class-string}> */
    private array $singletons = [];

    /** @var list<array{ref: WeakReference<object>, declaredClass: class-string}> */
    private array $scoped = [];

    public function __construct(private readonly InitDestroyInvoker $invoker) {}

    /**
     * $declaredClass here is the LIFECYCLE lookup key — the class InitDestroyInvoker's compiled
     * manifest actually carries #[PreDestroy] method names under. For an ordinary component that IS
     * the manifest's declared class; for a #[Bean] method returning an INTERFACE, the caller
     * (RegisterBeanPostProcessorsPass) passes the CONCRETE class captured at initialization time
     * (guaranteed non-proxy then — see that pass's invariant-4 note), never the interface, because
     * ContextScanner never scans interfaces and the manifest would have no entry for one. Callers
     * MUST NOT re-derive this from $bean::class here or at drain() time: by the time drainSingletons()/
     * drainScoped() run, $bean may already be a proxy (created in afterInitialization()), whose
     * runtime class carries no manifest entry of its own — the whole reason this value must be
     * captured once, up front, and threaded through rather than recomputed later.
     *
     * @param  class-string  $declaredClass
     */
    public function register(object $bean, string $declaredClass, Scope $scope): void
    {
        if (! $this->invoker->hasDestroyMethods($declaredClass)) {
            return;
        }

        $entry = ['ref' => WeakReference::create($bean), 'declaredClass' => $declaredClass];

        if ($scope === Scope::Singleton) {
            // Compact already-dead entries before appending. This does NOT close the gap
            // documented in the class docblock above (a dead entry here was ALREADY silently
            // unreachable at #[PreDestroy] time either way) — it only bounds *memory*, converting
            // "one entry per request, forever, under Octane" (every #[Lazy] singleton
            // sandbox-resolved and immediately GC'd — see the docblock) into "one entry per
            // DISTINCT tracked abstract, steady-state". Cheap (proportional to the current ledger
            // size, itself now bounded) and behaviorally invisible: drain() already silently skips
            // a dead ref, so pruning it earlier changes nothing about what fires, only how much
            // dead weight sits in the array between now and the one-time drainSingletons() call.
            $this->singletons = array_values(array_filter(
                $this->singletons,
                static fn (array $e): bool => $e['ref']->get() !== null,
            ));
            $this->singletons[] = $entry;
        } elseif ($scope === Scope::Scoped) {
            $this->scoped[] = $entry;
        }
        // Scope::Transient: prototype-scoped beans are not lifecycle-managed — never tracked.
    }

    /**
     * Singleton drain: called once, at context close.
     */
    public function drainSingletons(): void
    {
        $this->drain($this->singletons);
        $this->singletons = [];
    }

    /**
     * Scoped drain: called PER REQUEST, and MUST run before forgetScopedInstances() — see the
     * class docblock.
     */
    public function drainScoped(): void
    {
        $this->drain($this->scoped);
        $this->scoped = [];
    }

    /**
     * @param  list<array{ref: WeakReference<object>, declaredClass: class-string}>  $entries
     */
    private function drain(array $entries): void
    {
        foreach (array_reverse($entries) as $entry) {
            $bean = $entry['ref']->get();
            if ($bean === null) {
                continue;
            }

            $this->invoker->invokeDestroy($bean, $entry['declaredClass']);
        }
    }
}
