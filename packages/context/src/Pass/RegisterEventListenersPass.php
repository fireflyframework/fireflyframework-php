<?php

declare(strict_types=1);

namespace Firefly\Context\Pass;

use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;
use Firefly\Context\Event\DispatcherEventPublisher;
use Firefly\Context\Scanner\ContextManifest;
use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Registers every #[AsEventListener] method against Illuminate's event dispatcher, in ONE
 * already-#[Order]-sorted sweep — Illuminate's dispatch loop fires listeners in REGISTRATION order,
 * so registering the complete, pre-sorted list in a single pass recovers #[Order] exactly; letting
 * listeners register themselves piecemeal (e.g. one pass per discovered component) would let a
 * later-discovered listener silently append at the tail and defeat #[Order] — the same reasoning
 * RegisterBeanPostProcessorsPass documents for its composite extenders.
 *
 * #[AsEventListener] methods are read from the compiled ContextManifest (BootContext::$contextManifest)
 * — never by reflecting a component's declared class at boot. ContextScanner already resolved each
 * listener's $event (inferring it from the listener method's first parameter type ONCE, at scan
 * time, if it was left null) and its $order, so this pass does nothing but look the descriptor up
 * per definition and register what it finds — the same zero-reflection-at-load contract every other
 * Firefly manifest keeps. The order sorted on is the attribute's OWN $order (the #[Order] convention
 * applied per listener method, since one class may declare several listener methods needing
 * independent ordering), read purely from the manifest, never from a resolved bean.
 *
 * 🔴 THE BLOCKING REQUIREMENT this pass exists to satisfy: every raw listener is routed through
 * DispatcherEventPublisher::guardListener(). Illuminate\Events\Dispatcher::invokeListeners() breaks
 * UNCONDITIONALLY the instant any listener returns exactly `false` — regardless of the $halt flag —
 * so an unguarded listener whose last expression happens to be falsy (trivially easy by accident,
 * e.g. `return $repository->delete($id);`) would silently starve every listener registered after it.
 * Skipping this wrapping ships that bug live in production while every unit test of guardListener()
 * in isolation still passes green — see RegisterEventListenersPassTest's end-to-end proof.
 *
 * The listener closure resolves its target bean via the container on EVERY dispatch (never caching
 * it at registration time) — mirroring DispatcherEventPublisher's own per-call dispatcher
 * resolution — so it always observes the bean's current, fully post-processed (possibly proxied)
 * form.
 *
 * Listeners are looked up not only for each definition's OWN class, but for every non-empty
 * `#[Bean]` method return type too (M4 review #5, Minor 5 — a real gap, not merely a documented
 * limitation): `ContextScanner::describe()` captures `#[AsEventListener]` for ANY concrete class in
 * a scanned root, including one that is never itself a `#[Component]` but is instead PRODUCED by a
 * `#[Bean]` factory method (`#[Configuration] class C { #[Bean] fn(): RedisCache {...} }`) — for
 * that shape, the manifest holds an entry keyed `forClass('App\RedisCache')`, never `forClass('C')`.
 * `EagerSingletonsPass` and `RegisterBeanPostProcessorsPass` both already iterate
 * `$descriptor->beans`/`$bean->returns` for exactly this reason (a `#[Bean]` output is a
 * first-class lifecycle-managed thing, not merely its declaring class); this pass does the same
 * here in its own boot-time sweep. The listener resolves its bean through the key
 * `ContainerRegistrar::registerBeans()` actually bound the factory under (`BeanBindingKeys` — the
 * return type for the ordinary single-#[Bean] case, the #[Bean] NAME when several #[Bean] methods
 * compete for one type), so it always observes the fully post-processed (possibly proxied) bean,
 * exactly like a plain `#[Component]`. A BINDING already visited (reachable as either a
 * definition's own class OR a bean's key) is never visited twice, so a class reachable both ways
 * cannot register the same listener method twice — while two competing beans, which are two
 * distinct bindings, each register their own. See `orderedListeners()` for why the class and the
 * key had to stop being one string.
 *
 * 🔴 THE CANONICAL HEXAGONAL SHAPE IS NOT HANDLED BY THIS SWEEP (M4 review #6, Important 1 — the
 * untreated twin of `d3a7688`, corrected here; do NOT reintroduce the false claim this replaces).
 * For `#[Bean] fn(): SomePort` — an INTERFACE declared return type, the shape `docs/modules/
 * context.md` calls canonical — `$bean->returns` IS the interface, and `ContextScanner` NEVER scans
 * an interface (see its class docblock), so `forClass($bean->returns)` above is structurally
 * guaranteed to return null: this sweep can only ever find listeners for a `#[Bean]` method whose
 * declared return type IS a concrete class (or a plain `#[Component]`'s own class). It is NOT
 * symmetric with `#[PostConstruct]`/`#[PreDestroy]`, which recover the interface case via the
 * concrete class captured at `RegisterBeanPostProcessorsPass`'s `extend()` seam (invariant 4,
 * REFINED — see that pass's docblock).
 *
 * The interface case is instead recovered by `self::registerListenersFor()` below, called from
 * `RegisterBeanPostProcessorsPass`'s SAME composite extender that already captures the concrete
 * class for lifecycle — reusing that established mechanism rather than inventing a parallel one
 * (a second `container->extend()` per abstract would violate invariant 2). Because the concrete
 * class of an interface-declared `#[Bean]` is only knowable once the factory actually runs,
 * registration for THAT shape happens at the bean's first resolution — eagerly, during
 * `EagerSingletonsPass` (900), for a non-`#[Lazy]` `Scope::Singleton` bean, or at first real use,
 * for every other shape (`#[Lazy]` `Scope::Singleton`, `Scope::Scoped`, `Scope::Transient`) — rather
 * than in this pass's single pre-sorted sweep.
 *
 * MEASURED LIMITATION, corrected here (M4 review #8, Important — this docblock previously claimed
 * the recovery happens "still well before the application ever dispatches a real event"; false,
 * execution-measured): the eager-`Scope::Singleton` row is a real correctness gap, not merely a
 * timing quirk — `EagerSingletonsPass` resolves eager beans one at a time, in manifest order, so an
 * event published earlier in that same pass (e.g. from another eager bean's `#[PostConstruct]` — the
 * exact scenario `EagerSingletonsPass`'s own class docblock names as the reason it runs after this
 * pass) is silently missed by an interface-produced listener whose own turn has not yet arrived; any
 * event after that turn, including every post-boot dispatch, is heard normally. For every other row
 * — `#[Lazy]` `Scope::Singleton`, `Scope::Scoped`, `Scope::Transient` — nothing ever resolves the
 * bean at boot, so the listener never registers and never fires until application code happens to
 * resolve it by some other path; silently, with no error. `#[Order]`-comparability against this
 * sweep's listeners is a real, secondary consequence of the eager row alone (a listener recovered
 * there, when it registers in time, always lands after this sweep's) — it is not the limitation; the
 * two failure modes above are. See `RegisterBeanPostProcessorsPass::registerLateBoundListeners()`'s
 * own docblock for the full account and docs/modules/context.md's Events section for the user-facing
 * one.
 */
final class RegisterEventListenersPass implements BootPass
{
    public function phase(): BootPhase
    {
        return BootPhase::EventListeners;
    }

    public function order(): int
    {
        return 0;
    }

    public function run(BootContext $context): void
    {
        $container = $context->container;

        /** @var Dispatcher $dispatcher */
        $dispatcher = $container->make('events');

        foreach ($this->orderedListeners($context) as [$boundKey, $method, $event]) {
            $raw = static function (mixed ...$arguments) use ($container, $boundKey, $method): mixed {
                /** @var object $bean */
                $bean = $container->make($boundKey);

                return $bean->{$method}(...$arguments);
            };

            $dispatcher->listen($event, DispatcherEventPublisher::guardListener($raw));
        }
    }

    /**
     * Discovery is per CLASS (listener metadata lives on a class); registration is per BEAN.
     *
     * Those coincide for a #[Component] and for an uncontested #[Bean], which is why one loop keyed
     * on `$bean->returns` was right for as long as a #[Bean] method's return type was also its
     * container key. It stopped being right when firefly/container taught ContainerRegistrar to
     * honour #[Primary]/#[Qualifier] on #[Bean] methods: with SEVERAL #[Bean] methods producing one
     * type, each competitor is bound under its own #[Bean] NAME and the bare type key becomes an
     * ALIAS of the #[Primary] winner — or, with no #[Primary], a factory that throws a
     * NoUniqueBeanDefinition-style ConfigurationException. Both halves then went wrong at once:
     *
     *  - ONE registration for N beans. `$visited` is keyed by class, so a type produced twice was
     *    collected once. That is still exactly right for DISCOVERY — re-reading one class's
     *    metadata would duplicate the listener — but it meant only ONE listener existed for two
     *    beans that each declared it, and the sibling's #[AsEventListener] simply never fired.
     *  - INVOKED THROUGH THE WRONG KEY. `make($bean->returns)` on a contested type reaches the
     *    #[Primary] winner, so the listener ran against the winner's instance no matter which bean
     *    declared it; with no #[Primary] it hit the ambiguity guard and threw at DISPATCH time —
     *    turning a valid application (two same-typed beans, injected only by #[Qualifier]) into a
     *    runtime failure the first time any event was published.
     *
     * So `$visited` now keys on the CONTAINER KEY (BeanBindingKeys), which IS the class for every
     * component and every uncontested #[Bean] — identical behavior, including the "reachable as
     * both a component class and a bean return type" dedupe this guard was written for — and is the
     * distinct #[Bean] name for each competitor, so each one registers its own listener and invokes
     * it through its own binding. See CompetingBeansPassTest.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: int}> [container key, method, event, order]
     */
    private function orderedListeners(BootContext $context): array
    {
        $keys = BeanBindingKeys::fromDefinitions($context->definitions);

        $entries = [];

        /** @var array<string, true> $visited guards against registering the same binding twice */
        $visited = [];

        foreach ($context->definitions->all() as $definition) {
            $this->collectListenersFor($context, $definition->class(), $definition->class(), $visited, $entries);

            foreach ($definition->descriptor->beans as $bean) {
                // Null means the registrar bound nothing for this #[Bean] method (see
                // BeanBindingKeys::keyFor()) — there is no binding to invoke a listener through.
                $boundKey = $keys->keyFor($bean);
                if ($boundKey === null) {
                    continue;
                }

                $this->collectListenersFor($context, $bean->returns, $boundKey, $visited, $entries);
            }
        }

        usort($entries, static fn (array $a, array $b): int => $a[3] <=> $b[3] ?: $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);

        return $entries;
    }

    /**
     * $lookupClass is the class whose manifest entry carries the #[AsEventListener] metadata;
     * $boundKey is the container key the listener resolves its bean through at dispatch. They are
     * the same string everywhere except for a competing #[Bean] — see orderedListeners().
     *
     * @param  array<string, true>  $visited
     * @param  list<array{0: string, 1: string, 2: string, 3: int}>  $entries
     */
    private function collectListenersFor(BootContext $context, string $lookupClass, string $boundKey, array &$visited, array &$entries): void
    {
        if (isset($visited[$boundKey])) {
            return;
        }
        $visited[$boundKey] = true;

        $descriptor = $context->contextManifest->forClass($lookupClass);
        if ($descriptor === null) {
            return;
        }

        foreach ($descriptor->listeners as $listener) {
            $entries[] = [$boundKey, $listener['method'], $listener['event'], $listener['order']];
        }
    }

    /**
     * Registers every `#[AsEventListener]` found on `$lookupClass` in the compiled `ContextManifest`
     * onto the dispatcher, invoking through `$invokeThrough` at dispatch time (never at registration
     * time — the bean is resolved fresh on every dispatch, exactly like `run()`'s own closures
     * above, so it always observes the fully post-processed, possibly proxied, form).
     *
     * The SOLE reason this is a public static entry point rather than staying private to this
     * class: `RegisterBeanPostProcessorsPass`'s composite extender is the ONLY place a `#[Bean]`
     * method's declared-INTERFACE-return concrete class becomes knowable (see that pass's
     * invariant-4 note and this class's own docblock, "THE CANONICAL HEXAGONAL SHAPE IS NOT HANDLED
     * BY THIS SWEEP"). `$lookupClass` there is the concrete class captured at init; `$invokeThrough`
     * is the declared abstract (interface) the container actually bound the factory under, so
     * invocation still goes through the SAME binding/scope every other caller resolves — never the
     * concrete class directly, which the container may never have bound at all. This is NOT a
     * second, parallel registration mechanism: it is the exact same guardListener()-wrapped,
     * manifest-driven, per-call-resolved closure shape built above, called from a second call site
     * instead of reimplemented at one.
     */
    public static function registerListenersFor(
        Dispatcher $dispatcher,
        ContextManifest $contextManifest,
        Container $container,
        string $lookupClass,
        string $invokeThrough,
    ): void {
        $descriptor = $contextManifest->forClass($lookupClass);
        if ($descriptor === null) {
            return;
        }

        $listeners = $descriptor->listeners;
        usort($listeners, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        foreach ($listeners as $listener) {
            $method = $listener['method'];

            $raw = static function (mixed ...$arguments) use ($container, $invokeThrough, $method): mixed {
                /** @var object $bean */
                $bean = $container->make($invokeThrough);

                return $bean->{$method}(...$arguments);
            };

            $dispatcher->listen($listener['event'], DispatcherEventPublisher::guardListener($raw));
        }
    }
}
