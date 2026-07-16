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
use Firefly\Context\Event\DispatcherEventPublisher;
use Firefly\Context\Pass\EagerSingletonsPass;
use Firefly\Context\Pass\RegisterBeanPostProcessorsPass;
use Firefly\Context\Pass\RegisterEventListenersPass;
use Firefly\Context\Scanner\ContextManifest;
use Firefly\Context\Scanner\ContextScanner;
use Firefly\Context\Tests\LateBoundListenerBootEventFixtures\BootProbeEvent;
use Firefly\Context\Tests\LateBoundListenerBootEventFixtures\BootRecorder;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Events\Dispatcher as IlluminateDispatcher;

/**
 * M4 review #8, Important — the boot-event case named by the brief: a real domain event published
 * from a #[PostConstruct] callback fired DURING eager resolution (phase 900) — the exact scenario
 * EagerSingletonsPassTest's "an event published from a #[PostConstruct] during eager resolution IS
 * received" pins for a plain #[Component] listener. Here the ONLY variable is whether the listener is
 * produced by a #[Bean] factory whose declared return type is its own concrete class, or an
 * INTERFACE — reusing BeanProducedInterfaceListenerTest's one-variable-control shape, widened to a
 * boot-time publish. This is a REAL scan (Firefly\Container\Scanner\ComponentScanner +
 * Firefly\Context\Scanner\ContextScanner) through a REAL Firefly\Container\Registrar\
 * ContainerRegistrar, a REAL Illuminate\Container\Container, and a REAL Illuminate\Events\Dispatcher.
 */
/**
 * @return array{0: ComponentManifest, 1: ContextManifest}
 */
function scanLateBoundListenerBootEventFixtures(): array
{
    $psr4 = ['Firefly\\Context\\Tests\\LateBoundListenerBootEventFixtures\\' => __DIR__.'/LateBoundListenerBootEventFixtures'];

    $componentManifest = new ComponentManifest((new ComponentScanner)->scan($psr4));
    $contextManifest = new ContextManifest((new ContextScanner)->scan($psr4));

    return [$componentManifest, $contextManifest];
}

/**
 * @return array{0: Container, 1: BootRecorder}
 */
function bootLateBoundListenerBootEventPipeline(ComponentManifest $componentManifest, ContextManifest $contextManifest): array
{
    $container = new Container;
    $container->instance('events', new IlluminateDispatcher($container));
    $container->instance(DispatcherEventPublisher::class, new DispatcherEventPublisher($container));

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

    // The real M4 instance-stage order: BeanPostProcessors(700) installs the composite extenders ->
    // EventListeners(800) registers ConcreteBootListener's listener from the manifest (nothing to
    // find for BootListenerPort, an unscanned interface) -> EagerSingletons(900) resolves
    // EventPublisher FIRST (#[Order(-100)]), publishing BootProbeEvent from its #[PostConstruct]
    // before EITHER of BootConfig's two beans is resolved; only then does it resolve
    // ConcreteBootListener and BootListenerPort's own beans (both default order 0).
    (new RegisterBeanPostProcessorsPass)->run($context);
    (new RegisterEventListenersPass)->run($context);
    (new EagerSingletonsPass)->run($context);

    /** @var BootRecorder $recorder */
    $recorder = $container->make(BootRecorder::class);

    return [$container, $recorder];
}

it('an interface-produced #[Bean] listener silently MISSES a real domain event published from another eager bean\'s #[PostConstruct] during eager resolution — the concrete-return arm does not', function () {
    [$componentManifest, $contextManifest] = scanLateBoundListenerBootEventFixtures();
    [, $recorder] = bootLateBoundListenerBootEventPipeline($componentManifest, $contextManifest);

    // By the time the pipeline above returns, EventPublisher's #[PostConstruct] has already
    // published ONE BootProbeEvent, strictly before BootListenerPort's bean was resolved (order -100
    // sorts before both order-0 entries). ConcreteBootListener's listener was already registered by
    // the phase-800 sweep (independent of resolution order), so it heard the event; the
    // interface-produced listener had not yet been recovered by the late-bound path, so it did not.
    expect($recorder->events)->toContain('Concrete:listener')
        ->and($recorder->events)->not->toContain('Interface:listener');
});

it('CONTROL: after eager resolution finishes, the SAME interface-produced listener IS wired — the miss above is a timing gap, not a permanently dead listener', function () {
    [$componentManifest, $contextManifest] = scanLateBoundListenerBootEventFixtures();
    [$container, $recorder] = bootLateBoundListenerBootEventPipeline($componentManifest, $contextManifest);

    // EagerSingletonsPass resolved BootListenerPort's bean before returning (it too is eager,
    // non-#[Lazy]) — which is what recovers its listener via the late-bound path. Dispatching again,
    // post-boot, is the confound check: both listeners are wired, so the miss measured above is
    // conditioned on the real variable (declared return type + resolution timing), not on the
    // interface arm being unwired altogether.
    /** @var Dispatcher $dispatcher */
    $dispatcher = $container->make('events');
    $dispatcher->dispatch(new BootProbeEvent);

    $counts = array_count_values($recorder->events);

    expect($counts['Concrete:listener'] ?? 0)->toBe(2)
        ->and($counts['Interface:listener'] ?? 0)->toBe(1);
});
