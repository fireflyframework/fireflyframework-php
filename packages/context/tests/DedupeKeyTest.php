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
use Firefly\Context\Pass\EagerSingletonsPass;
use Firefly\Context\Pass\RegisterBeanPostProcessorsPass;
use Firefly\Context\Pass\RegisterEventListenersPass;
use Firefly\Context\Scanner\ContextManifest;
use Firefly\Context\Scanner\ContextScanner;
use Firefly\Context\Tests\DedupeKeyFixtures\DedupeEvent;
use Firefly\Context\Tests\DedupeKeyFixtures\DedupeRecorder;
use Firefly\Context\Tests\DedupeKeyFixtures\ReadPort;
use Firefly\Context\Tests\DedupeKeyFixtures\VaryEvent;
use Firefly\Context\Tests\DedupeKeyFixtures\VaryPort;
use Firefly\Context\Tests\DedupeKeyFixtures\WritePort;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Events\Dispatcher as IlluminateDispatcher;

/**
 * M4 review #8, Minor — the late-bound listener dedupe guard (`RegisterBeanPostProcessorsPass::
 * registerLateBoundListeners()`'s `$registered` array) must answer "have I already registered
 * listeners FOR THIS ABSTRACT (`$declaredClass`)", never "have I seen this RUNTIME CONCRETE CLASS
 * under any abstract" — the same inferred-vs-asked substitution review #7 fixed one line above this
 * guard. Both fixtures directories below are REAL scans (Firefly\Container\Scanner\ComponentScanner +
 * Firefly\Context\Scanner\ContextScanner) through a REAL Firefly\Container\Registrar\
 * ContainerRegistrar, a REAL Illuminate\Container\Container, and a REAL Illuminate\Events\Dispatcher.
 */
/**
 * @return array{0: ComponentManifest, 1: ContextManifest}
 */
function scanDedupeKeyFixtures(): array
{
    $psr4 = ['Firefly\\Context\\Tests\\DedupeKeyFixtures\\' => __DIR__.'/DedupeKeyFixtures'];

    $componentManifest = new ComponentManifest((new ComponentScanner)->scan($psr4));
    $contextManifest = new ContextManifest((new ContextScanner)->scan($psr4));

    return [$componentManifest, $contextManifest];
}

/**
 * @return array{0: Container, 1: DedupeRecorder}
 */
function bootDedupeKeyPipeline(ComponentManifest $componentManifest, ContextManifest $contextManifest): array
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

    // BeanPostProcessors(700) installs the composite extenders -> EventListeners(800) does its
    // boot-time sweep (a no-op here: every listener in this fixture set is produced by a #[Bean]
    // method returning an INTERFACE, so nothing is scanned under that key) -> EagerSingletons(900)
    // resolves ReadPort AND WritePort (both default Scope::Singleton, not #[Lazy]) — which is what
    // actually triggers RepoConfig's two composite extenders and exercises the dedupe guard for
    // Minor (a). VaryPort is Scope::Transient, so EagerSingletonsPass never touches it; its own test
    // resolves it explicitly, below.
    (new RegisterBeanPostProcessorsPass)->run($context);
    (new RegisterEventListenersPass)->run($context);
    (new EagerSingletonsPass)->run($context);

    /** @var DedupeRecorder $recorder */
    $recorder = $container->make(DedupeRecorder::class);

    return [$container, $recorder];
}

it('registers the late-bound listener for BOTH abstracts a single concrete class is bound under — M4 review #8, Minor (a): the guard must key on the ABSTRACT, not the concrete class', function () {
    [$componentManifest, $contextManifest] = scanDedupeKeyFixtures();

    [$container, $recorder] = bootDedupeKeyPipeline($componentManifest, $contextManifest);

    // Sanity: two DISTINCT Repo singletons, one per abstract — already resolved eagerly by
    // EagerSingletonsPass inside the pipeline above (both default Scope::Singleton, non-#[Lazy]);
    // make() here just returns the cached instances.
    $read = $container->make(ReadPort::class);
    $write = $container->make(WritePort::class);
    expect($read)->not->toBe($write);

    /** @var Dispatcher $dispatcher */
    $dispatcher = $container->make('events');
    $dispatcher->dispatch(new DedupeEvent('probe'));

    // ONE event; TWO distinct Repo instances, each independently registered as a listener. Before
    // the fix, keying on $concreteClass (Repo::class, identical for both) meant the SECOND abstract
    // resolved during EagerSingletonsPass (WritePort, since eager entries sort by (order, abstract)
    // and 'ReadPort' < 'WritePort' alphabetically) found $registered[Repo::class] already true and
    // never registered its own listener — so 'Repo:listener' fired only ONCE despite two live
    // listeners.
    expect(array_count_values($recorder->events)['Repo:listener'] ?? 0)->toBe(2);
});

it('only the FIRST-seen concrete class of a Scope::Transient interface-returning #[Bean] ever gets its listener REGISTERED — M4 review #8, Minor (b): the docblock\'s disclosed edge case, now measured TRUE', function () {
    [$componentManifest, $contextManifest] = scanDedupeKeyFixtures();
    [$container] = bootDedupeKeyPipeline($componentManifest, $contextManifest);

    /** @var Dispatcher $dispatcher */
    $dispatcher = $container->make('events');

    // Before either resolution: nothing registered yet for VaryPort's listener event (interface,
    // never scanned by the boot-time sweep — the late-bound path is the only way it ever registers).
    expect($dispatcher->getListeners(VaryEvent::class))->toHaveCount(0);

    // Resolution 1: factory returns VaryImplA -> registers ONE listener entry via the late-bound
    // path, under VaryPort's guard.
    $container->make(VaryPort::class);
    expect($dispatcher->getListeners(VaryEvent::class))->toHaveCount(1);

    // Resolution 2: SAME abstract (Scope::Transient re-invokes the extender), factory now returns a
    // DIFFERENT concrete class (VaryImplB). This — not a dispatch afterwards — is the cleanest place
    // to measure the guard directly: a real dispatch would trigger YET ANOTHER fresh resolution of
    // the Scope::Transient bean when the already-registered listener resolves $invokeThrough, which
    // is an orthogonal, already-covered behavior (see LateBoundListenerScopeMatrixTest) that would
    // confound what's under test here. Before this fix (keyed on $concreteClass), VaryImplB is a
    // DIFFERENT key from VaryImplA, so a SECOND listener entry registers here — contradicting the
    // docblock's own disclosed edge case ("only the FIRST-seen concrete class's listeners are ever
    // registered"). Keyed on $declaredClass (VaryPort, the SAME abstract both resolutions share),
    // the guard is already set, so no second entry registers, making that disclosure true as written.
    $container->make(VaryPort::class);
    expect($dispatcher->getListeners(VaryEvent::class))->toHaveCount(1);
});
