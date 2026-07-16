<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Config\Profile\Profiles;
use Firefly\Container\Registrar\ContainerRegistrar;
use Firefly\Container\Scanner\ComponentManifest;
use Firefly\Container\Scanner\ComponentScanner;
use Firefly\Context\Boot\BootContext;
use Firefly\Context\Condition\ConditionEvaluationReport;
use Firefly\Context\Condition\ConditionEvaluator;
use Firefly\Context\Definition\BeanDefinition;
use Firefly\Context\Definition\BeanDefinitionRegistry;
use Firefly\Context\Lifecycle\DisposableBeanRegistry;
use Firefly\Context\Pass\EagerSingletonsPass;
use Firefly\Context\Pass\RegisterBeanPostProcessorsPass;
use Firefly\Context\Pass\RegisterEventListenersPass;
use Firefly\Context\Scanner\ContextManifest;
use Firefly\Context\Scanner\ContextScanner;
use Firefly\Context\Tests\BeanListenerInterfaceFixtures\CacheA;
use Firefly\Context\Tests\BeanListenerInterfaceFixtures\CacheB;
use Firefly\Context\Tests\BeanListenerInterfaceFixtures\ChildCache;
use Firefly\Context\Tests\BeanListenerInterfaceFixtures\ListenerFireRecorder;
use Firefly\Context\Tests\BeanListenerInterfaceFixtures\ListenerPort;
use Firefly\Context\Tests\BeanListenerInterfaceFixtures\ParentCache;
use Firefly\Context\Tests\BeanListenerInterfaceFixtures\ProbeEvent;
use Firefly\Context\Tests\BeanListenerInterfaceFixtures\TransientListenerPort;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Events\Dispatcher as IlluminateDispatcher;

/**
 * M4 review #6, Important 1, and review #7, Important — THE FAULT-INJECTION CONTROL.
 *
 * Reuses review #6's exact discriminating shape, widened by review #7 to a full one-variable control
 * over the THREE shapes `$bean->returns` can take relative to the runtime concrete class: three
 * structurally IDENTICAL `#[Bean]`-produced classes (CacheA/CacheB/ChildCache — same
 * #[AsEventListener]/#[PreDestroy] method names and bodies), the ONLY variable between them being the
 * declared return type of the `#[Bean]` factory method that produces each one:
 *   - `ConfigA::makeA(): CacheA`        — identical (concrete self)
 *   - `ConfigB::makeB(): ListenerPort`  — an INTERFACE, "the canonical hexagonal shape"
 *   - `ConfigC::makeC(): ParentCache`   — a concrete SUPERCLASS (the factory returns a ChildCache)
 *
 * This is a REAL scan (Firefly\Container\Scanner\ComponentScanner + Firefly\Context\Scanner\
 * ContextScanner) over REAL fixture files, through a REAL Firefly\Container\Registrar\
 * ContainerRegistrar, a REAL Illuminate\Container\Container, and a REAL Illuminate\Events\Dispatcher
 * — never hand-built descriptors standing in for a scan, and never a mock dispatcher. Before the
 * Important-1 fix this test FAILS: 'B:listener' is never recorded, because
 * RegisterEventListenersPass's boot-time sweep looks listeners up via
 * `contextManifest->forClass($bean->returns)`, and `$bean->returns` for ConfigB's factory is
 * `ListenerPort::class` — an interface `ContextScanner` never scans, so the lookup silently returns
 * null. 'A:listener' fires throughout, both before and after the fix — it is the concrete-return
 * arm's job to prove the fix didn't regress the already-working shape. 'C:listener' is the review #7
 * regression: fix #6's `$concreteClass === $declaredClass` gate is false for ARM C too (ChildCache !==
 * ParentCache), but unlike ARM B the boot sweep already registered ARM C's listener (ParentCache is
 * concrete, so it IS scanned) — so the late-bound path registering it again fires it TWICE per event.
 */
/**
 * @return array{0: ComponentManifest, 1: ContextManifest}
 */
function scanBeanListenerInterfaceFixtures(): array
{
    $psr4 = ['Firefly\\Context\\Tests\\BeanListenerInterfaceFixtures\\' => __DIR__.'/BeanListenerInterfaceFixtures'];

    $componentManifest = new ComponentManifest((new ComponentScanner)->scan($psr4));
    $contextManifest = new ContextManifest((new ContextScanner)->scan($psr4));

    return [$componentManifest, $contextManifest];
}

/**
 * @return array{0: BootContext, 1: Container, 2: ListenerFireRecorder}
 */
function bootBeanListenerInterfacePipeline(ComponentManifest $componentManifest, ContextManifest $contextManifest): array
{
    $container = new Container;
    $container->instance('events', new IlluminateDispatcher($container));

    (new ContainerRegistrar($container))->register($componentManifest);

    $config = new Config(new Repository([]));
    $profiles = new Profiles([]);
    $registry = new BeanDefinitionRegistry;

    foreach ($componentManifest->components as $component) {
        $registry->add(new BeanDefinition($component));
    }

    $context = new BootContext(
        container: $container,
        definitions: $registry,
        config: $config,
        profiles: $profiles,
        conditions: new ConditionEvaluator($config, $profiles),
        report: new ConditionEvaluationReport,
        contextManifest: $contextManifest,
    );

    // The real M4 instance-stage order: BeanPostProcessors(700) installs the composite extender ->
    // EventListeners(800) does its boot-time sweep -> EagerSingletons(900) resolves both #[Bean]
    // factories (Scope::Singleton, not #[Lazy]) — which is what triggers the composite extender for
    // ConfigB's ListenerPort abstract, the ONLY place `CacheB::class` (the concrete class) becomes
    // knowable.
    (new RegisterBeanPostProcessorsPass)->run($context);
    (new RegisterEventListenersPass)->run($context);
    (new EagerSingletonsPass)->run($context);

    /** @var ListenerFireRecorder $recorder */
    $recorder = $container->make(ListenerFireRecorder::class);

    return [$context, $container, $recorder];
}

it('registers #[AsEventListener] for a #[Bean] method whose declared return type is an INTERFACE — control: only the return type differs from the concrete-return arm', function () {
    [$componentManifest, $contextManifest] = scanBeanListenerInterfaceFixtures();

    // Sanity: ContextScanner captured BOTH concrete classes fully, keyed by their OWN class name —
    // confirms any failure below is about the LOOKUP KEY used by RegisterEventListenersPass, not
    // about scanning.
    $cacheADescriptor = $contextManifest->forClass(CacheA::class);
    $cacheBDescriptor = $contextManifest->forClass(CacheB::class);

    expect($cacheADescriptor)->not->toBeNull()
        ->and($cacheADescriptor?->listeners)->toHaveCount(1)
        ->and($cacheBDescriptor)->not->toBeNull()
        ->and($cacheBDescriptor?->listeners)->toHaveCount(1)
        ->and($contextManifest->forClass(ListenerPort::class))->toBeNull();

    [, $container, $recorder] = bootBeanListenerInterfacePipeline($componentManifest, $contextManifest);

    /** @var Dispatcher $dispatcher */
    $dispatcher = $container->make('events');
    $dispatcher->dispatch(new ProbeEvent('probe'));

    // ARM A (concrete #[Bean] return type) fires — unaffected by the fix, proves no regression.
    expect($recorder->events)->toContain('A:listener');

    // ARM B (interface #[Bean] return type) — THE FAULT. Before the Important-1 fix this assertion
    // fails: 'B:listener' is never recorded because forClass(ListenerPort::class) is null.
    expect($recorder->events)->toContain('B:listener');
});

it('fires an #[AsEventListener] recovered from a #[Bean] method EXACTLY ONCE per event, regardless of whether the declared return type is identical, an interface, or a concrete superclass — M4 review #7, Important, one-variable control', function () {
    [$componentManifest, $contextManifest] = scanBeanListenerInterfaceFixtures();

    // Sanity: ContextScanner captured ParentCache (concrete, scanned directly) AND ChildCache
    // (concrete, inherits ParentCache's #[AsEventListener] via ReflectionClass::getMethods()) — this
    // is precisely the shape that made fix #6's `$concreteClass === $declaredClass` gate unsound:
    // the boot sweep already found this listener under ParentCache (forClass($bean->returns)), so
    // the late-bound recovery path must recognise that and NOT register it again under ChildCache.
    $parentDescriptor = $contextManifest->forClass(ParentCache::class);
    $childDescriptor = $contextManifest->forClass(ChildCache::class);

    expect($parentDescriptor)->not->toBeNull()
        ->and($parentDescriptor?->listeners)->toHaveCount(1)
        ->and($childDescriptor)->not->toBeNull()
        ->and($childDescriptor?->listeners)->toHaveCount(1);

    [, $container, $recorder] = bootBeanListenerInterfacePipeline($componentManifest, $contextManifest);

    /** @var Dispatcher $dispatcher */
    $dispatcher = $container->make('events');
    $dispatcher->dispatch(new ProbeEvent('probe'));

    $counts = array_count_values($recorder->events);

    // ONE event dispatched. The ONLY variable across the three arms is the #[Bean] factory's declared
    // return type (identical / interface / concrete superclass) — every arm must fire EXACTLY ONCE.
    // Before the review #7 fix, ARM C (superclass) fires TWICE: once from RegisterEventListenersPass's
    // boot-time sweep (which already found it via forClass(ParentCache::class)), and once more from
    // RegisterBeanPostProcessorsPass's late-bound recovery path, whose `$concreteClass ===
    // $declaredClass` gate is false for this shape (ChildCache !== ParentCache) exactly like the
    // interface arm, but WITHOUT that arm's justification (the sweep already handled it here).
    expect($counts['A:listener'] ?? 0)->toBe(1)
        ->and($counts['B:listener'] ?? 0)->toBe(1)
        ->and($counts['C:listener'] ?? 0)->toBe(1);
});

it('still runs #[PreDestroy] for the interface-produced bean after the fix (no regression of the d3a7688/Critical-2 lifecycle fix)', function () {
    [$componentManifest, $contextManifest] = scanBeanListenerInterfaceFixtures();

    [, $container] = bootBeanListenerInterfacePipeline($componentManifest, $contextManifest);

    /** @var ListenerFireRecorder $recorder */
    $recorder = $container->make(ListenerFireRecorder::class);

    /** @var DisposableBeanRegistry $disposables */
    $disposables = $container->make(DisposableBeanRegistry::class);
    $disposables->drainSingletons();

    expect($recorder->events)->toContain('A:predestroy')
        ->and($recorder->events)->toContain('B:predestroy');
});

it('never registers an interface-produced Scope::Transient bean\'s listener more than once, even when resolved several times — M4 review #7, Minor 1', function () {
    [$componentManifest, $contextManifest] = scanBeanListenerInterfaceFixtures();

    [, $container, $recorder] = bootBeanListenerInterfacePipeline($componentManifest, $contextManifest);

    // TransientListenerPort is Scope::Transient (unlike ARM B's Scope::Singleton ListenerPort): every
    // make() call rebuilds a NEW CacheT and re-invokes the composite extender, which is what actually
    // exercises the $registered[$declaredClass] dedupe guard. A prior version of this test resolved
    // the Scope::Singleton ListenerPort instead, whose second make() call is served from the
    // container's cached instance and never re-invokes the extender at all — so that version passed
    // identically with the guard deleted (see fault injection below). Resolving three times here is
    // deliberately MORE than the two calls the original test made, to make the discrimination obvious
    // regardless of exactly how many extra resolutions occur.
    $container->make(TransientListenerPort::class);
    $container->make(TransientListenerPort::class);
    $container->make(TransientListenerPort::class);

    /** @var Dispatcher $dispatcher */
    $dispatcher = $container->make('events');
    $dispatcher->dispatch(new ProbeEvent('probe'));

    // ONE event dispatched, after THREE resolutions. With the guard: registered once, at the FIRST
    // resolution (EagerSingletonsPass never touches a Transient bean, so the first make() above is
    // genuinely the first resolution) — fires exactly once. Without the guard (fault-injected below):
    // fires three times, once per resolution.
    expect(array_count_values($recorder->events)['T:listener'] ?? 0)->toBe(1);
});
