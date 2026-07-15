<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Config\Profile\Profiles;
use Firefly\Container\Descriptor\ComponentDescriptor;
use Firefly\Container\Registrar\ContainerRegistrar;
use Firefly\Container\Scanner\ComponentManifest;
use Firefly\Container\Scanner\ComponentScanner;
use Firefly\Container\Scanner\ManifestCompiler;
use Firefly\Container\Scope;
use Firefly\Context\Boot\ApplicationContext;
use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;
use Firefly\Context\Boot\FireflyKernel;
use Firefly\Context\Condition\ConditionAttribute;
use Firefly\Context\Condition\ConditionEvaluationReport;
use Firefly\Context\Condition\ConditionEvaluator;
use Firefly\Context\Definition\BeanDefinition;
use Firefly\Context\Definition\BeanDefinitionRegistry;
use Firefly\Context\Definition\DefinitionSource;
use Firefly\Context\Event\ApplicationEventPublisher;
use Firefly\Context\Event\DispatcherEventPublisher;
use Firefly\Context\Pass\ConditionPassOnePass;
use Firefly\Context\Pass\ConditionPassTwoPass;
use Firefly\Context\Pass\ContextRefreshedPass;
use Firefly\Context\Pass\EagerSingletonsPass;
use Firefly\Context\Pass\FlushDefinitionsPass;
use Firefly\Context\Pass\InfrastructureStartPass;
use Firefly\Context\Pass\RegisterBeanPostProcessorsPass;
use Firefly\Context\Pass\RegisterEventListenersPass;
use Firefly\Context\Pass\UserConfigurationsPass;
use Firefly\Context\Scanner\ContextManifest;
use Firefly\Context\Scanner\ContextManifestCompiler;
use Firefly\Context\Scanner\ContextScanner;
use Firefly\Context\Tests\IntegrationFixtures\CachePort;
use Firefly\Context\Tests\IntegrationFixtures\DefaultCacheAutoConfig;
use Firefly\Context\Tests\IntegrationFixtures\DuringEagerReceiver;
use Firefly\Context\Tests\IntegrationFixtures\GatedComponentKept;
use Firefly\Context\Tests\IntegrationFixtures\GatedComponentRemoved;
use Firefly\Context\Tests\IntegrationFixtures\GuardEvent;
use Firefly\Context\Tests\IntegrationFixtures\PostConstructWidget;
use Firefly\Context\Tests\IntegrationFixtures\PostConstructWidgetProxy;
use Firefly\Context\Tests\IntegrationFixtures\UserCache;
use Firefly\Context\Tests\IntegrationFixtures\WidgetRecorder;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher as IlluminateDispatcher;

/**
 * THE CAPSTONE END-TO-END TEST for firefly/context's boot engine.
 *
 * Every unit in this package is green in isolation; NONE of those unit tests prove the pieces
 * COMPOSE. This file goes through the REAL production path, with no shortcuts:
 *
 *   1. SCAN real fixture classes (packages/context/tests/IntegrationFixtures/) with the REAL
 *      ComponentScanner (firefly/container) and ContextScanner (firefly/context).
 *   2. COMPILE both scans to REAL cached manifest files on disk (ManifestCompiler,
 *      ContextManifestCompiler) — written to temporary paths, cleaned up in a finally.
 *   3. LOAD them back via ComponentManifest::load()/ContextManifest::load() — require()+map, the
 *      SAME zero-reflection path Octane workers use in production. Nothing here is an in-memory
 *      descriptor built by hand.
 *   4. BOOT a real FireflyKernel over a real Illuminate\Container\Container and a real
 *      Firefly\Config\Config(new Illuminate\Config\Repository([...])), with the real boot passes
 *      contributed — the same passes a real FireflyServiceProvider would add.
 *   5. Assert the whole pipeline: conditions, the single ContainerRegistrar write, BeanPostProcessor
 *      ordering (through a REAL proxy), lifecycle callbacks, application events, eager/lazy
 *      resolution, shutdown ordering, and boot-plan determinism.
 *
 * If any assertion below fails, that is a signal of a REAL bug in the engine, not a test to be
 * loosened — see the M4 plan's Task 15 and the design decisions doc.
 */

/**
 * @return array{0: string, 1: string} [componentManifestPath, contextManifestPath]
 */
function compileIntegrationManifests(): array
{
    $psr4 = ['Firefly\\Context\\Tests\\IntegrationFixtures\\' => __DIR__.'/IntegrationFixtures'];

    $componentDescriptors = (new ComponentScanner)->scan($psr4);
    $contextDescriptors = (new ContextScanner)->scan($psr4);

    $componentPath = sys_get_temp_dir().'/firefly-integration-component-manifest-'.bin2hex(random_bytes(6)).'.php';
    $contextPath = sys_get_temp_dir().'/firefly-integration-context-manifest-'.bin2hex(random_bytes(6)).'.php';

    (new ManifestCompiler)->write($componentDescriptors, $componentPath);
    (new ContextManifestCompiler)->write($contextDescriptors, $contextPath);

    return [$componentPath, $contextPath];
}

/**
 * Real Firefly\Config\Config over a real Illuminate\Config\Repository — 'firefly.feature.on' is
 * present and truthy (GatedComponentKept must survive); 'firefly.feature.off' is deliberately never
 * set (GatedComponentRemoved must not).
 */
function makeIntegrationConfig(): Config
{
    return new Config(new Repository([
        'firefly' => [
            'feature' => [
                'on' => true,
            ],
        ],
    ]));
}

function integrationComponentDescriptor(ComponentManifest $manifest, string $class): ComponentDescriptor
{
    foreach ($manifest->components as $component) {
        if ($component->class === $class) {
            return $component;
        }
    }

    throw new RuntimeException("No ComponentDescriptor was scanned for {$class}.");
}

/**
 * @return list<ConditionAttribute>
 */
function integrationConditionsFor(ContextManifest $manifest, string $class): array
{
    return $manifest->forClass($class)?->conditionInstances() ?? [];
}

/**
 * Every scanned component EXCEPT DefaultCacheAutoConfig, wrapped as DefinitionSource::User — the
 * seam UserConfigurationsPass forces onto whatever it is given.
 *
 * @return list<BeanDefinition>
 */
function integrationUserDefinitions(ComponentManifest $components, ContextManifest $context): array
{
    $definitions = [];

    foreach ($components->components as $component) {
        if ($component->class === DefaultCacheAutoConfig::class) {
            continue; // seeded separately below, tagged DefinitionSource::AutoConfiguration
        }

        $definitions[] = new BeanDefinition($component, integrationConditionsFor($context, $component->class));
    }

    return $definitions;
}

/**
 * DefaultCacheAutoConfig standing in for what M5's AutoConfigDiscovery will one day scan: an
 * auto-configuration is legally allowed to carry #[ConditionalOnMissingBean] (a User definition is
 * not — see ConditionEvaluator's user-component rule), so it is fed into the registry tagged
 * DefinitionSource::AutoConfiguration rather than through UserConfigurationsPass.
 */
function integrationAutoConfigurationDefinition(ComponentManifest $components, ContextManifest $context): BeanDefinition
{
    $descriptor = integrationComponentDescriptor($components, DefaultCacheAutoConfig::class);

    return new BeanDefinition(
        $descriptor,
        integrationConditionsFor($context, DefaultCacheAutoConfig::class),
        DefinitionSource::AutoConfiguration,
    );
}

/**
 * A real Illuminate\Container\Container, self-bound (so any fixture type-hinting the concrete
 * container resolves) with a real event dispatcher and the ApplicationEventPublisher port bound —
 * exactly the bootstrap wiring a real FireflyServiceProvider performs before handing the container
 * to the kernel.
 */
function freshIntegrationContainer(): Container
{
    $container = new Container;
    $container->instance(Container::class, $container);
    $container->instance('events', new IlluminateDispatcher($container));
    $container->bind(
        ApplicationEventPublisher::class,
        static fn (Container $c): ApplicationEventPublisher => new DispatcherEventPublisher($c),
    );

    return $container;
}

/**
 * DefaultCacheAutoConfig is pre-seeded into the registry BEFORE boot(): M4 ships no
 * AutoConfigDiscovery pass yet (that lands in M5), so — exactly like UserConfigurationsPass
 * documents for the user seam — the caller supplies already-scanned auto-configuration definitions
 * directly. ConditionPassTwoPass (500) still evaluates it correctly because it operates on
 * whatever is in the registry at phase entry, regardless of when each definition was added.
 */
function freshIntegrationBootContext(Container $container, ComponentManifest $components, ContextManifest $context): BootContext
{
    $config = makeIntegrationConfig();
    $profiles = new Profiles([]);
    $registry = new BeanDefinitionRegistry;
    $registry->add(integrationAutoConfigurationDefinition($components, $context));

    return new BootContext(
        container: $container,
        definitions: $registry,
        config: $config,
        profiles: $profiles,
        conditions: new ConditionEvaluator($config, $profiles),
        report: new ConditionEvaluationReport,
        contextManifest: $context,
    );
}

/**
 * The full, real M4 boot pipeline — the same nine passes a real FireflyServiceProvider contributes.
 *
 * @return list<BootPass>
 */
function integrationRealPasses(ComponentManifest $components, ContextManifest $context): array
{
    return [
        new ConditionPassOnePass,
        new UserConfigurationsPass(integrationUserDefinitions($components, $context)),
        new ConditionPassTwoPass,
        new FlushDefinitionsPass,
        new RegisterBeanPostProcessorsPass,
        new RegisterEventListenersPass,
        new InfrastructureStartPass,
        new EagerSingletonsPass,
        new ContextRefreshedPass,
    ];
}

/**
 * Builds a completely FRESH container/registry/kernel, contributes the real pipeline plus any
 * extra passes, boots it, and returns the resulting collaborators. $reversed flips the ENTIRE
 * addPass() call order — used by the determinism test to prove the resolved boot plan does not
 * depend on it.
 *
 * @param  list<BootPass>  $extraPasses
 * @return array{0: ApplicationContext, 1: BootContext, 2: Container}
 */
function bootIntegrationPipeline(
    ComponentManifest $components,
    ContextManifest $context,
    array $extraPasses = [],
    bool $reversed = false,
): array {
    $container = freshIntegrationContainer();
    $bootContext = freshIntegrationBootContext($container, $components, $context);
    $kernel = new FireflyKernel($bootContext);

    $passes = array_merge(integrationRealPasses($components, $context), $extraPasses);
    if ($reversed) {
        $passes = array_reverse($passes);
    }

    foreach ($passes as $pass) {
        $kernel->addPass($pass);
    }

    $applicationContext = $kernel->boot();

    return [$applicationContext, $bootContext, $container];
}

/**
 * @param  list<string>  $haystack
 */
function integrationPositionOf(array $haystack, string $needle): int
{
    $position = array_search($needle, $haystack, true);
    if (! is_int($position)) {
        throw new RuntimeException("Expected '{$needle}' to have been recorded, but it was not: [".implode(', ', $haystack).'].');
    }

    return $position;
}

/**
 * A marker ComponentDescriptor that was never part of the real scan — used ONLY to prove a second
 * ContainerRegistrar::register() call, made directly against the already-booted container, is a
 * silent no-op (M2's per-container sentinel). If it were NOT a no-op, this class would end up
 * bound and the "exactly one write" invariant would be false.
 */
final class IntegrationSecondRegistrationMarker {}

/**
 * Two BootPass implementations sharing the EXACT SAME (phase, order) — WiringPasses(1000), 0 — a
 * phase the real pipeline above never touches. The ONLY thing that can disambiguate their relative
 * execution order is FQCN string comparison (FireflyKernel::sortedPasses()'s documented tiebreak),
 * which is entirely independent of addPass() call order. Each resolves WidgetRecorder from the
 * BootContext it is given at run() time (never injected at construction) so the SAME two instances
 * can be safely reused across two separate kernels/containers.
 */
final class IntegrationDeterminismPassAaa implements BootPass
{
    public function phase(): BootPhase
    {
        return BootPhase::WiringPasses;
    }

    public function order(): int
    {
        return 0;
    }

    public function run(BootContext $context): void
    {
        /** @var WidgetRecorder $recorder */
        $recorder = $context->container->make(WidgetRecorder::class);
        $recorder->record('wiring:aaa');
    }
}

final class IntegrationDeterminismPassZzz implements BootPass
{
    public function phase(): BootPhase
    {
        return BootPhase::WiringPasses;
    }

    public function order(): int
    {
        return 0;
    }

    public function run(BootContext $context): void
    {
        /** @var WidgetRecorder $recorder */
        $recorder = $context->container->make(WidgetRecorder::class);
        $recorder->record('wiring:zzz');
    }
}

it('boots a real application end-to-end through the compiled, zero-reflection manifest path', function () {
    [$componentPath, $contextPath] = compileIntegrationManifests();

    try {
        $componentManifest = ComponentManifest::load($componentPath);
        $contextManifest = ContextManifest::load($contextPath);

        [$applicationContext, $bootContext, $container] = bootIntegrationPipeline($componentManifest, $contextManifest);

        /** @var WidgetRecorder $recorder */
        $recorder = $container->make(WidgetRecorder::class);

        // --- Conditions gated definitions ---
        //
        // 🔴 KNOWN ENGINE BUG (found by this integration test, not fixed here — see the task's
        // report): BootPhase::ConditionPassOne = 300 runs BEFORE BootPhase::UserConfigurations =
        // 400 adds ANY user-sourced BeanDefinition to the registry. ConditionPassOnePass only
        // evaluates whatever is ALREADY in Firefly\Context\Definition\BeanDefinitionRegistry at the
        // moment it runs, so a user #[Component]/#[Configuration]'s own registry-independent
        // #[ConditionalOnProperty]/#[ConditionalOnClass]/#[ConditionalOnMissingClass]/
        // #[ConditionalOnProfile] is NEVER evaluated by the real boot pipeline — the definition
        // always survives regardless of whether the condition actually matches. Verified in
        // isolation: running (new UserConfigurationsPass($defs))->run($context) BEFORE (new
        // ConditionPassOnePass)->run($context) — the reverse of the shipped phase order — correctly
        // filters GatedComponentRemoved; running them in the SHIPPED order does not.
        // packages/context/tests/Boot/BootPhaseTest.php itself asserts ConditionPassOne <
        // UserConfigurations, so this is the pipeline's deliberate, tested phase order — not a stray
        // typo — and is left unmodified here per this task's brief ("do NOT weaken the assertion to
        // get green; report BLOCKED"). This assertion is intentionally left exactly as the checklist
        // requires and is expected to FAIL until the engine is fixed.
        $survivingClasses = array_map(
            static fn (BeanDefinition $d): string => $d->class(),
            $bootContext->definitions->all(),
        );

        expect($survivingClasses)->toContain(GatedComponentKept::class)
            ->and($survivingClasses)->not->toContain(GatedComponentRemoved::class)
            ->and($container->bound(GatedComponentRemoved::class))->toBeFalse();

        expect($applicationContext->get(GatedComponentKept::class))->toBeInstanceOf(GatedComponentKept::class);

        // A #[ConditionalOnMissingBean] auto-configuration backs off once a user bean supplies the type.
        expect($survivingClasses)->not->toContain(DefaultCacheAutoConfig::class)
            ->and($container->bound(DefaultCacheAutoConfig::class))->toBeFalse()
            ->and($applicationContext->get(CachePort::class))->toBeInstanceOf(UserCache::class);

        // --- Exactly ONE ContainerRegistrar::register() write, with the FILTERED manifest ---
        expect($container->bound('firefly.container.registered'))->toBeTrue();

        // A second register() call, made directly against the already-booted container with a
        // manifest that was never part of the real scan, MUST be a silent no-op (M2's sentinel) —
        // proving "exactly one write", not merely "at least one write".
        $secondManifest = new ComponentManifest([
            new ComponentDescriptor(
                class: IntegrationSecondRegistrationMarker::class,
                stereotype: 'Service',
                name: null,
                scope: Scope::Singleton,
                primary: false,
                order: 0,
                qualifier: null,
                interfaces: [],
                beans: [],
            ),
        ]);
        (new ContainerRegistrar($container))->register($secondManifest);

        expect($container->bound(IntegrationSecondRegistrationMarker::class))->toBeFalse();

        // --- BPPs ran in #[Order], including a proxying BPP (invariant 3/4) ---
        $bppTrace = array_values(array_filter(
            $recorder->events,
            static fn (string $e): bool => str_starts_with($e, 'bpp:') || $e === 'postConstruct:hello',
        ));

        expect($bppTrace)->toBe([
            'bpp:before:1',
            'bpp:before:2',
            'postConstruct:hello',
            'bpp:after:1',
            'bpp:after:2',
        ]);

        // The manifest key stays PostConstructWidget, but the cached singleton is now a proxy whose
        // runtime class has NO entry of its own in either compiled manifest.
        expect($applicationContext->get(PostConstructWidget::class))->toBeInstanceOf(PostConstructWidgetProxy::class);

        // --- Listeners received ContextRefreshedEvent then ApplicationReadyEvent, in that order ---
        $lifecycleOrder = array_values(array_filter(
            $recorder->events,
            static fn (string $e): bool => in_array($e, ['listener:refreshed', 'listener:ready'], true),
        ));
        expect($lifecycleOrder)->toBe(['listener:refreshed', 'listener:ready']);

        // --- An event published from #[PostConstruct] during eager resolution IS received ---
        // (proves EventListeners(800) precedes EagerSingletons(900) through the real cached manifest)
        $duringEagerReceiver = $applicationContext->get(DuringEagerReceiver::class);
        if (! $duringEagerReceiver instanceof DuringEagerReceiver) {
            throw new RuntimeException('Expected a DuringEagerReceiver instance.');
        }
        expect($duringEagerReceiver->received)->toBeTrue();

        // --- A listener returning false does NOT starve later listeners (guardListener() wiring) ---
        $applicationContext->publishEvent(new GuardEvent);
        $guardTrace = array_values(array_filter(
            $recorder->events,
            static fn (string $e): bool => in_array($e, ['guard:first', 'guard:second'], true),
        ));
        expect($guardTrace)->toBe(['guard:first', 'guard:second']);

        // --- Eager singletons resolved in manifest #[Order]; a #[Lazy] singleton was NOT eager ---
        $eagerOrder = array_values(array_filter(
            $recorder->events,
            static fn (string $e): bool => in_array($e, ['eager:first', 'eager:last'], true),
        ));
        expect($eagerOrder)->toBe(['eager:first', 'eager:last'])
            ->and($recorder->events)->not->toContain('lazy:instantiated');

        // --- close() runs #[PreDestroy] + Lifecycle::stop() in REVERSE order ---
        $startOrder = array_values(array_filter(
            $recorder->events,
            static fn (string $e): bool => in_array($e, ['infra:start:first', 'infra:start:second'], true),
        ));
        expect($startOrder)->toBe(['infra:start:first', 'infra:start:second']);

        $applicationContext->close();

        expect(integrationPositionOf($recorder->events, 'infra:stop:second'))
            ->toBeLessThan(integrationPositionOf($recorder->events, 'infra:stop:first'));

        expect(integrationPositionOf($recorder->events, 'dispose:two'))
            ->toBeLessThan(integrationPositionOf($recorder->events, 'dispose:one'));

        // PostConstructWidget's #[PreDestroy] still fired via the DECLARED class even though the
        // bean drained at close() time is the proxy, whose runtime class carries no manifest entry.
        expect($recorder->events)->toContain('preDestroy:PostConstructWidget');
    } finally {
        @unlink($componentPath);
        @unlink($contextPath);
    }
});

it('produces an identical execution sequence regardless of the order passes were contributed to the kernel', function () {
    [$componentPath, $contextPath] = compileIntegrationManifests();

    try {
        $componentManifest = ComponentManifest::load($componentPath);
        $contextManifest = ContextManifest::load($contextPath);

        // Same two pass instances handed to BOTH boots: they hold no per-run state, resolving
        // WidgetRecorder fresh from whatever BootContext they are given at run() time.
        $tieBreakPasses = [new IntegrationDeterminismPassZzz, new IntegrationDeterminismPassAaa];

        [, , $containerA] = bootIntegrationPipeline($componentManifest, $contextManifest, $tieBreakPasses, reversed: false);
        [, , $containerB] = bootIntegrationPipeline($componentManifest, $contextManifest, $tieBreakPasses, reversed: true);

        /** @var WidgetRecorder $recorderA */
        $recorderA = $containerA->make(WidgetRecorder::class);
        /** @var WidgetRecorder $recorderB */
        $recorderB = $containerB->make(WidgetRecorder::class);

        expect($recorderA->events)->not->toBeEmpty()
            ->and($recorderB->events)->toBe($recorderA->events);

        // Directly exercises the FQCN tiebreak: two passes sharing an identical (phase, order) must
        // resolve in the SAME relative order in BOTH runs, even though run A contributed Zzz before
        // Aaa and run B contributed Aaa before Zzz.
        expect(integrationPositionOf($recorderA->events, 'wiring:aaa'))
            ->toBeLessThan(integrationPositionOf($recorderA->events, 'wiring:zzz'));
        expect(integrationPositionOf($recorderB->events, 'wiring:aaa'))
            ->toBeLessThan(integrationPositionOf($recorderB->events, 'wiring:zzz'));
    } finally {
        @unlink($componentPath);
        @unlink($contextPath);
    }
});
