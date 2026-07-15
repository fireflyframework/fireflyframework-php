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
use Firefly\Context\Boot\FireflyServiceProvider;
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
use Illuminate\Foundation\Application;

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
 *   4. BOOT through a REAL Illuminate\Foundation\Application and a REAL FireflyServiceProvider
 *      subclass (IntegrationPipelineProvider, below) contributing the real boot passes — register()
 *      + Application::boot(), never FireflyKernel::boot() called directly. FireflyServiceProvider's
 *      OWN wiring (the ApplicationEventPublisher binding, the booting()/booted() phase split, the
 *      ApplicationContext singleton) all run for real; only FireflyKernel's construction from a real
 *      Firefly\Config\Config(new Illuminate\Config\Repository([...])) is still hand-assembled (see
 *      bootIntegrationPipeline()'s own docblock for exactly why that part remains out of scope).
 *   5. Assert the whole pipeline: conditions, the single ContainerRegistrar write, BeanPostProcessor
 *      ordering (through a REAL proxy), lifecycle callbacks, application events (including the
 *      ApplicationEventPublisher port itself being bound by the provider, not by this test), eager/lazy
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
 * A real Illuminate\Foundation\Application — NOT a bare Illuminate\Container\Container — because
 * this file now boots through the REAL FireflyServiceProvider (see bootIntegrationPipeline()
 * below), and only Illuminate\Foundation\Application has booting()/booted()/terminating() hooks for
 * it to register against. Illuminate\Foundation\Application's own constructor already
 * self-binds Container::class (registerBaseBindings()) and registers Illuminate's EventServiceProvider
 * (registerBaseServiceProviders()), which binds 'events' — both verified against the installed
 * laravel/framework source, so neither needs to be hand-bound here the way the old bare-Container
 * version of this helper had to.
 *
 * Deliberately does NOT bind ApplicationEventPublisher itself: a previous version of this file did,
 * under a docblock that (falsely) claimed the hand-binding was "exactly the bootstrap wiring a real
 * FireflyServiceProvider performs" — it was not, FireflyServiceProvider performed none of it, and
 * the hand-bind is what let this capstone test pass while the port stayed genuinely unwired in
 * production (see FireflyServiceProvider::register() and the M4 re-review). Now that
 * FireflyServiceProvider really does bind the port (see bootIntegrationPipeline()), no test-side
 * shortcut is needed — or permitted.
 */
function freshIntegrationApplication(): Application
{
    return new Application;
}

/**
 * DefaultCacheAutoConfig is pre-seeded into the registry BEFORE boot(): M4 ships no
 * AutoConfigDiscovery/AutoConfigurations pass yet (that lands in M5), so — exactly like
 * UserConfigurationsPass documents for the user seam — the caller supplies already-scanned
 * auto-configuration definitions directly. ConditionPassOnePass (400) correctly leaves it alone
 * (scoped to DefinitionSource::User) and ConditionPassTwoPass (600) still evaluates it correctly
 * because it operates on whatever DefinitionSource::AutoConfiguration definitions are in the
 * registry at phase entry, regardless of when each one was added.
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
 * Contributes an EXACT, caller-supplied list of BootPass instances via passes() — the only reason
 * this subclass exists is so bootIntegrationPipeline() below keeps full control over which passes
 * run and in what CONTRIBUTED order (needed by the determinism test's $reversed flip) while still
 * registering through the REAL FireflyServiceProvider machinery: register()'s
 * ApplicationEventPublisher binding, the booting()/booted() phase split, and booted()'s
 * ApplicationContext singleton + terminating() shutdown wiring. A real production
 * FireflyServiceProvider subclass would instead return a fixed list from passes(); this one accepts
 * it via the constructor purely because this test needs several DIFFERENT pass lists across its
 * scenarios, over a single shared base class.
 */
final class IntegrationPipelineProvider extends FireflyServiceProvider
{
    /**
     * @param  list<BootPass>  $providedPasses
     */
    public function __construct($app, private readonly array $providedPasses)
    {
        parent::__construct($app);
    }

    public function passes(): array
    {
        return $this->providedPasses;
    }
}

/**
 * Builds a completely FRESH application, contributes the real pipeline plus any extra passes
 * through a real FireflyServiceProvider subclass, boots it THROUGH THE PROVIDER (register() + a
 * real Application::boot()) — never by constructing FireflyKernel and calling boot() on it
 * directly — and returns the resulting collaborators. $reversed flips the ENTIRE addPass() call
 * order — used by the determinism test to prove the resolved boot plan does not depend on it.
 *
 * FireflyKernel itself is still hand-built and hand-bound below, BEFORE the provider registers —
 * that part is genuinely unavoidable in this milestone and is NOT the shortcut this file used to
 * take. Building a REAL BootContext from a fresh Laravel application means bridging
 * ComponentScanner's/ContextScanner's compiled output into a populated BeanDefinitionRegistry
 * (plus resolving config/profiles), which is the still-deferred phase 200/500
 * AutoConfigDiscovery/AutoConfigurations bootstrap-layer seam (see docs/modules/context.md and
 * FireflyServiceProvider's own class docblock) — a later milestone's job, not
 * FireflyServiceProvider's. What IS FireflyServiceProvider's job — and what it now actually does,
 * exercised for real below — is binding ApplicationEventPublisher, running the kernel's
 * definition/instance phases from booting()/booted(), and binding+closing ApplicationContext. None
 * of that is hand-wired here anymore.
 *
 * @param  list<BootPass>  $extraPasses
 * @return array{0: ApplicationContext, 1: BootContext, 2: Application}
 */
function bootIntegrationPipeline(
    ComponentManifest $components,
    ContextManifest $context,
    array $extraPasses = [],
    bool $reversed = false,
): array {
    $app = freshIntegrationApplication();
    $bootContext = freshIntegrationBootContext($app, $components, $context);
    $kernel = new FireflyKernel($bootContext);
    $app->instance(FireflyKernel::class, $kernel);

    $passes = array_merge(integrationRealPasses($components, $context), $extraPasses);
    if ($reversed) {
        $passes = array_reverse($passes);
    }

    $app->register(new IntegrationPipelineProvider($app, $passes));
    $app->boot();

    /** @var ApplicationContext $applicationContext */
    $applicationContext = $app->make(ApplicationContext::class);

    return [$applicationContext, $bootContext, $app];
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

        [$applicationContext, $bootContext, $app] = bootIntegrationPipeline($componentManifest, $contextManifest);

        /** @var WidgetRecorder $recorder */
        $recorder = $app->make(WidgetRecorder::class);

        // --- ApplicationEventPublisher is bound by FireflyServiceProvider — not hand-bound here ---
        //
        // This is the capstone regression test for the M4 re-review's Critical finding: nothing in
        // shipped code bound the ApplicationEventPublisher port, so a #[Component] injecting it (the
        // exact documented shape — see DuringEagerPublisher below) crashed boot the moment
        // EagerSingletonsPass tried to resolve it eagerly. A previous version of this test hand-bound
        // the port itself, under a docblock falsely claiming that was "exactly the bootstrap wiring a
        // real FireflyServiceProvider performs" — which hid the bug instead of catching it. There is
        // no hand-binding left anywhere in this file now: DuringEagerPublisher resolving successfully
        // below (via the real EagerSingletonsPass) IS the proof the provider's own binding is
        // load-bearing.
        expect($app->bound(ApplicationEventPublisher::class))->toBeTrue()
            ->and($app->make(ApplicationEventPublisher::class))->toBeInstanceOf(DispatcherEventPublisher::class);

        // --- Conditions gated definitions ---
        //
        // This assertion is the CAPSTONE regression test for a real engine bug this integration
        // test caught: BootPhase::ConditionPassOne used to run BEFORE BootPhase::UserConfigurations
        // added ANY user-sourced BeanDefinition to the registry, so ConditionPassOnePass evaluated
        // against an empty/partial registry and a user #[Component]/#[Configuration]'s own
        // registry-independent #[ConditionalOnProperty]/#[ConditionalOnClass]/
        // #[ConditionalOnMissingClass]/#[ConditionalOnProfile] was NEVER evaluated — the definition
        // always survived regardless of whether the condition actually matched. Fixed by reordering
        // BootPhase so each condition pass FOLLOWS its own definition source — see BootPhase's class
        // docblock and packages/context/tests/Boot/BootPhaseTest.php for the corrected chain
        // (UserConfigurations < ConditionPassOne < AutoConfigurations < ConditionPassTwo). Do not
        // "simplify" that order back — that is precisely how this bug shipped the first time.
        $survivingClasses = array_map(
            static fn (BeanDefinition $d): string => $d->class(),
            $bootContext->definitions->all(),
        );

        expect($survivingClasses)->toContain(GatedComponentKept::class)
            ->and($survivingClasses)->not->toContain(GatedComponentRemoved::class)
            ->and($app->bound(GatedComponentRemoved::class))->toBeFalse();

        expect($applicationContext->get(GatedComponentKept::class))->toBeInstanceOf(GatedComponentKept::class);

        // A #[ConditionalOnMissingBean] auto-configuration backs off once a user bean supplies the type.
        expect($survivingClasses)->not->toContain(DefaultCacheAutoConfig::class)
            ->and($app->bound(DefaultCacheAutoConfig::class))->toBeFalse()
            ->and($applicationContext->get(CachePort::class))->toBeInstanceOf(UserCache::class);

        // --- Exactly ONE ContainerRegistrar::register() write, with the FILTERED manifest ---
        expect($app->bound('firefly.container.registered'))->toBeTrue();

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
        (new ContainerRegistrar($app))->register($secondManifest);

        expect($app->bound(IntegrationSecondRegistrationMarker::class))->toBeFalse();

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

        [, , $appA] = bootIntegrationPipeline($componentManifest, $contextManifest, $tieBreakPasses, reversed: false);
        [, , $appB] = bootIntegrationPipeline($componentManifest, $contextManifest, $tieBreakPasses, reversed: true);

        /** @var WidgetRecorder $recorderA */
        $recorderA = $appA->make(WidgetRecorder::class);
        /** @var WidgetRecorder $recorderB */
        $recorderB = $appB->make(WidgetRecorder::class);

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
