<?php

declare(strict_types=1);

namespace Firefly\Context\Pass;

use Firefly\Container\Descriptor\ComponentDescriptor;
use Firefly\Container\Scope;
use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;
use Firefly\Context\Lifecycle\DisposableBeanRegistry;
use Firefly\Context\Lifecycle\InitDestroyInvoker;
use Firefly\Context\Processor\BeanPostProcessor;
use Firefly\Context\Processor\BeanPostProcessorChain;
use Firefly\Context\Scanner\ContextManifest;
use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Installs exactly ONE composite Illuminate extender per abstract (INVARIANT 2), delegating to a
 * single #[Order]-frozen BeanPostProcessorChain (INVARIANT 1: extend() is the only BeanPostProcessor
 * seam — see KernelContractTest). Illuminate's extenders fire in REGISTRATION order, so one extender
 * per BPP would let a later-discovered BPP silently append at the tail and defeat #[Order]; a single
 * composite extender per abstract keeps the frozen chain the only thing deciding order.
 *
 * BPPs are discovered AND ordered from the MANIFEST — never from a resolved instance (INVARIANT 3):
 * Firefly\Container\Container::orderOf() keys on $instance::class, so a proxied BPP would silently
 * sort to order 0. The ordered list is frozen, from ComponentDescriptor::$order/$class, BEFORE any
 * BPP is resolved — resolving a BPP class through the container CAN legitimately return a proxy
 * (e.g. if something already registered an extend() for that class), and the sort above must not
 * care either way.
 *
 * $declaredClass threaded into every extender is the MANIFEST's declared class (component class or
 * #[Bean] return type) — never $o::class — and is what gets passed to every
 * BeanPostProcessor::before/afterInitialization() call.
 *
 * INVARIANT 4, REFINED — "key on the declared class, never $bean::class" exists to be PROXY-safe:
 * a proxy's runtime class has no manifest entry, so using it as a lookup key silently finds nothing.
 * But its own corollary contract (see docs/modules/context.md's "proxy contract") is that a proxy is
 * ONLY ever created inside afterInitialization() (pass 2) — never earlier. That means the raw $bean
 * this extender closure receives, BEFORE chain->process() runs, is GUARANTEED non-proxy: capturing
 * $bean::class right here is therefore both safe and, for the #[PostConstruct]/#[PreDestroy]
 * LIFECYCLE lookup specifically, uniformly MORE correct than $declaredClass — for an ordinary
 * #[Component] the two are identical, but for a #[Bean] method whose declared return type is an
 * INTERFACE (the canonical hexagonal shape `#[Bean] fn(): SomePort`), $declaredClass IS that
 * interface, and ContextScanner never scans interfaces (see its class docblock), so the compiled
 * manifest has NO entry for it — a lookup keyed on $declaredClass in that case silently finds
 * nothing, which is exactly how a #[Bean]-returning-an-interface used to lose both
 * #[PostConstruct] and #[PreDestroy] with no error. $concreteClass is threaded through
 * BeanPostProcessorChain::process() as $lifecycleClass (the lookup key for InitDestroyInvoker
 * ONLY) and forward into DisposableBeanRegistry::register() so #[PreDestroy] still resolves at
 * context close, when the drained instance MAY by then be a proxy. Do NOT re-derive the class from
 * the instance at destroy time — see DisposableBeanRegistry's own docblock for why a WeakReference'd
 * bean's ::class cannot be trusted that late.
 *
 * The same extender ALSO registers the processed bean into the shared DisposableBeanRegistry (for
 * #[PreDestroy] at context close, see ApplicationContext::close()) — this is the ONE seam every bean
 * (component or #[Bean] factory output, eager or lazily resolved later during a request) passes
 * through, so it is the correct place to observe "a bean was just built" for lifecycle bookkeeping.
 *
 * It ALSO recovers #[AsEventListener] registration for a #[Bean] method whose declared return type
 * is an INTERFACE (M4 review #6, Important 1 — the untreated twin of the interface-blind lifecycle
 * gap fixed above by this same $concreteClass capture): RegisterEventListenersPass's own boot-time
 * sweep can only ever look up `contextManifest->forClass($bean->returns)`, and for that shape
 * `$bean->returns` IS the interface, which ContextScanner never scans — so this is the ONLY place
 * $concreteClass becomes knowable at all. $concreteClass === $declaredClass is exactly the signal
 * that RegisterEventListenersPass's sweep already handled this abstract (every plain #[Component]
 * and every #[Bean] method whose declared return type is its own concrete class), so this only ever
 * acts on the genuinely-unhandled interface shape — see registerLateBoundListeners()'s own docblock
 * for the registration-timing/#[Order] trade-off this implies, and RegisterEventListenersPass's
 * docblock for why this is NOT a second, parallel registration mechanism.
 *
 * BPP classes never post-process themselves into existence: they are resolved BEFORE any composite
 * extender is installed (so none of them can run through a chain, including their own, that does not
 * exist yet), and are explicitly excluded from the set of abstracts that get an extender at all.
 *
 * KNOWN, DELIBERATELY UNHANDLED LIMITATION — flush()+reboot over the SAME container: Illuminate's
 * Container::flush() does not clear installed extenders (see KernelContractTest, which pins this
 * unpublished behavior). If a future caller ever flush()es an already-booted container and then
 * reboots the SAME FireflyKernel over it, this pass would install a SECOND composite extender per
 * abstract on top of the surviving one — two distinct chains, so #[PostConstruct] would run twice
 * per bean. This is currently safe to leave unhandled because no shipped caller does that (Octane
 * never flush()es the long-lived worker application — only its per-request sandbox is discarded, and
 * StateResetter handles per-request state separately). It is NOT handled here; see
 * KernelContractTest for the full characterization before assuming otherwise.
 */
final class RegisterBeanPostProcessorsPass implements BootPass
{
    public function phase(): BootPhase
    {
        return BootPhase::BeanPostProcessors;
    }

    public function order(): int
    {
        return 0;
    }

    public function run(BootContext $context): void
    {
        $container = $context->container;
        $contextManifest = $context->contextManifest;
        $invoker = new InitDestroyInvoker($container, $contextManifest);
        $disposables = $this->disposableBeanRegistry($container, $invoker);

        // 'events' is unconditionally bound in real applications (Illuminate\Foundation\
        // Application's base bindings) and is what RegisterEventListenersPass itself resolves
        // unconditionally — but several of THIS pass's own unit tests build a bare
        // Illuminate\Container\Container with no 'events' binding at all, since this pass never
        // needed one before. Guard rather than assume, so late-bound listener recovery below is
        // simply a no-op wherever no dispatcher exists, instead of a hard dependency this pass
        // never had.
        $dispatcher = $container->bound('events') ? $container->make('events') : null;

        /** @var list<ComponentDescriptor> $descriptors */
        $descriptors = array_map(
            static fn ($definition): ComponentDescriptor => $definition->descriptor,
            $context->definitions->all(),
        );

        $bppClasses = $this->orderedBeanPostProcessorClasses($descriptors);

        /** @var list<BeanPostProcessor> $ordered */
        $ordered = array_map(
            static function (string $class) use ($container): BeanPostProcessor {
                /** @var BeanPostProcessor $instance */
                $instance = $container->make($class);

                return $instance;
            },
            $bppClasses,
        );

        $chain = new BeanPostProcessorChain($ordered, $invoker);

        $bppClassSet = array_fill_keys($bppClasses, true);

        /** @var array<string, true> $listenersRegisteredFor keyed by concrete class — see registerLateBoundListeners() */
        $listenersRegisteredFor = [];

        foreach ($this->abstractsToExtend($descriptors, $bppClassSet) as $abstract => $target) {
            [$declaredClass, $scope] = $target;

            $container->extend($abstract, static function (object $bean) use (
                $chain,
                $declaredClass,
                $scope,
                $disposables,
                $container,
                $dispatcher,
                $contextManifest,
                &$listenersRegisteredFor,
            ): object {
                // INVARIANT 4, REFINED (see class docblock): $bean here is guaranteed pre-proxy — a
                // proxy is only ever created inside afterInitialization(), below, inside
                // chain->process(). $bean::class is therefore safe AND, for the lifecycle lookup
                // specifically, more correct than $declaredClass whenever the two differ (a #[Bean]
                // method returning an interface).
                $concreteClass = $bean::class;

                $processed = $chain->process($bean, $declaredClass, $concreteClass);
                $disposables->register($processed, $concreteClass, $scope);

                if ($dispatcher !== null) {
                    self::registerLateBoundListeners(
                        $dispatcher,
                        $contextManifest,
                        $container,
                        $declaredClass,
                        $concreteClass,
                        $listenersRegisteredFor,
                    );
                }

                return $processed;
            });
        }
    }

    private function disposableBeanRegistry(Container $container, InitDestroyInvoker $invoker): DisposableBeanRegistry
    {
        if (! $container->bound(DisposableBeanRegistry::class)) {
            $container->instance(DisposableBeanRegistry::class, new DisposableBeanRegistry($invoker));
        }

        /** @var DisposableBeanRegistry $registry */
        $registry = $container->make(DisposableBeanRegistry::class);

        return $registry;
    }

    /**
     * Recovers RegisterEventListenersPass::registerListenersFor() for the ONE shape its own
     * boot-time sweep structurally cannot reach: a #[Bean] method whose declared return type is an
     * INTERFACE (M4 review #6, Important 1). See this class's own docblock for why this seam — and
     * not a second container->extend() — is the correct, reused place to do it.
     *
     * $concreteClass === $declaredClass is the exact signal that RegisterEventListenersPass's sweep
     * already handled this abstract completely: every plain #[Component] and every #[Bean] method
     * whose declared return type IS its own concrete class resolves to itself, and
     * `forClass($declaredClass)` there already found (or correctly found nothing for) whatever this
     * class carries. Only when the two differ — which, per invariant 4, can only happen for a
     * #[Bean] method returning an interface — could that sweep NOT have found it (forClass() on an
     * interface is structurally null; ContextScanner never scans one), so only that case is worth
     * checking here at all.
     *
     * $registered is keyed by concrete class and passed BY REFERENCE from the ONE composite
     * extender closure created in run() for this abstract. Under Octane that SAME closure instance
     * (installed once, at worker boot) survives for the worker's entire life — a shallow
     * `clone $this->app` per request copies the extenders array's closure REFERENCES, never
     * deep-clones them (see OctaneListener's own invariant-7 note on Illuminate\Container's clone
     * semantics) — so this guard is what stops a Scope::Singleton or Scope::Scoped bean's listeners
     * from being registered again on every later request that happens to trigger another
     * resolution: without it, the SAME shared, worker-lifetime Dispatcher (also resolved once, on
     * the original $app, and shared by reference into every sandbox) would accumulate one duplicate
     * registration per resolution and fire the listener multiple times per event. For
     * Scope::Transient, every make() call rebuilds and re-invokes this extender; if the factory
     * returns a DIFFERENT concrete class across calls (unusual, but not forbidden), only the
     * FIRST-seen concrete class's listeners are ever registered — a narrow, disclosed edge case.
     *
     * KNOWN, DISCLOSED LIMITATION — registration TIMING, not correctness: this runs at the bean's
     * FIRST resolution (EagerSingletonsPass, for a non-#[Lazy] bean — still well before the
     * application ever dispatches a real domain event — or first real use, for a #[Lazy] one),
     * never in RegisterEventListenersPass's single #[Order]-sorted boot sweep. A listener recovered
     * here therefore always ends up registered AFTER every listener that sweep already registered
     * for the same event, regardless of its own #[Order] value — see docs/modules/context.md's
     * Events section.
     *
     * @param  array<string, true>  $registered
     */
    private static function registerLateBoundListeners(
        Dispatcher $dispatcher,
        ContextManifest $contextManifest,
        Container $container,
        string $declaredClass,
        string $concreteClass,
        array &$registered,
    ): void {
        if ($concreteClass === $declaredClass || isset($registered[$concreteClass])) {
            return;
        }
        $registered[$concreteClass] = true;

        RegisterEventListenersPass::registerListenersFor($dispatcher, $contextManifest, $container, $concreteClass, $declaredClass);
    }

    /**
     * @param  list<ComponentDescriptor>  $descriptors
     * @return list<class-string>
     */
    private function orderedBeanPostProcessorClasses(array $descriptors): array
    {
        $bpps = array_values(array_filter(
            $descriptors,
            static fn (ComponentDescriptor $d): bool => in_array(BeanPostProcessor::class, $d->interfaces, true),
        ));

        usort($bpps, static fn (ComponentDescriptor $a, ComponentDescriptor $b): int => $a->order <=> $b->order ?: $a->class <=> $b->class);

        /** @var list<class-string> */
        return array_map(static fn (ComponentDescriptor $d): string => $d->class, $bpps);
    }

    /**
     * @param  list<ComponentDescriptor>  $descriptors
     * @param  array<string, true>  $bppClassSet
     * @return array<string, array{0: class-string, 1: Scope}>
     */
    private function abstractsToExtend(array $descriptors, array $bppClassSet): array
    {
        /** @var array<string, array{0: class-string, 1: Scope}> $abstracts */
        $abstracts = [];

        foreach ($descriptors as $descriptor) {
            if (! isset($bppClassSet[$descriptor->class])) {
                /** @var class-string $class */
                $class = $descriptor->class;
                $abstracts[$class] = [$class, $descriptor->scope];
            }

            foreach ($descriptor->beans as $bean) {
                if ($bean->returns !== '' && ! isset($bppClassSet[$bean->returns])) {
                    /** @var class-string $returns */
                    $returns = $bean->returns;
                    $abstracts[$returns] = [$returns, $bean->scope];
                }
            }
        }

        return $abstracts;
    }
}
