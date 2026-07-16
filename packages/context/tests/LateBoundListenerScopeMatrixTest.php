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
use Firefly\Context\Tests\LateBoundListenerScopeMatrixFixtures\LazyEvent;
use Firefly\Context\Tests\LateBoundListenerScopeMatrixFixtures\LazyPort;
use Firefly\Context\Tests\LateBoundListenerScopeMatrixFixtures\MatrixRecorder;
use Firefly\Context\Tests\LateBoundListenerScopeMatrixFixtures\ScopedEvent;
use Firefly\Context\Tests\LateBoundListenerScopeMatrixFixtures\ScopedPort;
use Firefly\Context\Tests\LateBoundListenerScopeMatrixFixtures\TransientEvent;
use Firefly\Context\Tests\LateBoundListenerScopeMatrixFixtures\TransientMatrixPort;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Events\Dispatcher as IlluminateDispatcher;

/**
 * M4 review #8, Important — the full scope matrix the brief names: #[Lazy] Scope::Singleton,
 * Scope::Scoped, and Scope::Transient, each with a concrete-return #[Bean] arm (control: registered
 * by the boot-time sweep regardless of scope/laziness) and an interface-return arm (the late-bound
 * path — registers ONLY once something resolves the bean, which none of these three rows ever do at
 * boot). This is a REAL scan (Firefly\Container\Scanner\ComponentScanner + Firefly\Context\Scanner\
 * ContextScanner) through a REAL Firefly\Container\Registrar\ContainerRegistrar, a REAL
 * Illuminate\Container\Container, and a REAL Illuminate\Events\Dispatcher.
 */
/**
 * @return array{0: ComponentManifest, 1: ContextManifest}
 */
function scanLateBoundListenerScopeMatrixFixtures(): array
{
    $psr4 = ['Firefly\\Context\\Tests\\LateBoundListenerScopeMatrixFixtures\\' => __DIR__.'/LateBoundListenerScopeMatrixFixtures'];

    $componentManifest = new ComponentManifest((new ComponentScanner)->scan($psr4));
    $contextManifest = new ContextManifest((new ContextScanner)->scan($psr4));

    return [$componentManifest, $contextManifest];
}

/**
 * @return array{0: Container, 1: MatrixRecorder}
 */
function bootLateBoundListenerScopeMatrixPipeline(ComponentManifest $componentManifest, ContextManifest $contextManifest): array
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

    // BeanPostProcessors(700) installs the composite extenders -> EventListeners(800) registers
    // every *ConcreteListener from the manifest (nothing to find for the *Port interfaces) ->
    // EagerSingletons(900) resolves NOTHING in this fixture set: every bean here is either #[Lazy]
    // Scope::Singleton, Scope::Scoped, or Scope::Transient — none of which EagerSingletonsPass ever
    // eagerly resolves.
    (new RegisterBeanPostProcessorsPass)->run($context);
    (new RegisterEventListenersPass)->run($context);
    (new EagerSingletonsPass)->run($context);

    /** @var MatrixRecorder $recorder */
    $recorder = $container->make(MatrixRecorder::class);

    return [$container, $recorder];
}

it('for a #[Lazy] Scope::Singleton #[Bean]: the concrete-return listener fires without anything resolving the bean; the interface-return listener never fires until something does', function () {
    [$componentManifest, $contextManifest] = scanLateBoundListenerScopeMatrixFixtures();
    [$container, $recorder] = bootLateBoundListenerScopeMatrixPipeline($componentManifest, $contextManifest);

    /** @var Dispatcher $dispatcher */
    $dispatcher = $container->make('events');
    $dispatcher->dispatch(new LazyEvent);

    // Nothing has resolved either bean yet — dispatch itself triggers the concrete arm's lazy
    // resolution (its listener closure calls container->make() fresh per dispatch, exactly like
    // every other listener this pass registers), but the interface arm's listener was never even
    // installed on the dispatcher, so dispatching does nothing for it.
    expect($recorder->events)->toContain('LazyConcrete:listener')
        ->and($recorder->events)->not->toContain('LazyInterface:listener');

    // CONTROL: only once something resolves LazyPort's bean does its late-bound listener register.
    $container->make(LazyPort::class);
    $dispatcher->dispatch(new LazyEvent);

    expect(array_count_values($recorder->events)['LazyInterface:listener'] ?? 0)->toBe(1);
});

it('for a Scope::Scoped #[Bean]: the concrete-return listener fires without anything resolving the bean; the interface-return listener never fires until something does', function () {
    [$componentManifest, $contextManifest] = scanLateBoundListenerScopeMatrixFixtures();
    [$container, $recorder] = bootLateBoundListenerScopeMatrixPipeline($componentManifest, $contextManifest);

    /** @var Dispatcher $dispatcher */
    $dispatcher = $container->make('events');
    $dispatcher->dispatch(new ScopedEvent);

    expect($recorder->events)->toContain('ScopedConcrete:listener')
        ->and($recorder->events)->not->toContain('ScopedInterface:listener');

    // CONTROL.
    $container->make(ScopedPort::class);
    $dispatcher->dispatch(new ScopedEvent);

    expect(array_count_values($recorder->events)['ScopedInterface:listener'] ?? 0)->toBe(1);
});

it('for a Scope::Transient #[Bean]: the concrete-return listener fires without anything resolving the bean; the interface-return listener never fires until something does', function () {
    [$componentManifest, $contextManifest] = scanLateBoundListenerScopeMatrixFixtures();
    [$container, $recorder] = bootLateBoundListenerScopeMatrixPipeline($componentManifest, $contextManifest);

    /** @var Dispatcher $dispatcher */
    $dispatcher = $container->make('events');
    $dispatcher->dispatch(new TransientEvent);

    expect($recorder->events)->toContain('TransientConcrete:listener')
        ->and($recorder->events)->not->toContain('TransientInterface:listener');

    // CONTROL.
    $container->make(TransientMatrixPort::class);
    $dispatcher->dispatch(new TransientEvent);

    expect(array_count_values($recorder->events)['TransientInterface:listener'] ?? 0)->toBe(1);
});
