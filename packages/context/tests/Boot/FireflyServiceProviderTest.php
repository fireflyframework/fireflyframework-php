<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Config\Profile\Profiles;
use Firefly\Container\Descriptor\ComponentDescriptor;
use Firefly\Container\Scope;
use Firefly\Context\Boot\ApplicationContext;
use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;
use Firefly\Context\Boot\FireflyKernel;
use Firefly\Context\Boot\FireflyServiceProvider;
use Firefly\Context\Condition\ConditionEvaluationReport;
use Firefly\Context\Condition\ConditionEvaluator;
use Firefly\Context\Definition\BeanDefinition;
use Firefly\Context\Definition\BeanDefinitionRegistry;
use Firefly\Context\Event\ApplicationEventPublisher;
use Firefly\Context\Event\ContextClosedEvent;
use Firefly\Context\Event\DispatcherEventPublisher;
use Firefly\Context\Lifecycle\PreDestroy;
use Firefly\Context\Octane\OctaneListener;
use Firefly\Context\Pass\EagerSingletonsPass;
use Firefly\Context\Pass\FlushDefinitionsPass;
use Firefly\Context\Pass\InfrastructureStartPass;
use Firefly\Context\Pass\RegisterBeanPostProcessorsPass;
use Firefly\Context\Scanner\ContextDescriptor;
use Firefly\Context\Scanner\ContextManifest;
use Firefly\Kernel\Lifecycle;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\WorkerStopping;

/**
 * FireflyServiceProvider is exercised over a REAL Illuminate\Foundation\Application — a bare one,
 * built directly (not via Orchestra\Testbench), specifically so these tests control exactly WHEN
 * register() and boot() happen: Testbench's own TestCase runs the real BootProviders bootstrapper
 * as part of creating $this->app, so a testbench app is ALREADY booted before a test method even
 * starts — which would make every provider registered afterwards boot IMMEDIATELY inside
 * register() (see Illuminate\Foundation\Application::register()'s `if ($this->isBooted())
 * bootProvider()` branch), defeating the entire point of these ordering tests. A bare Application
 * gives the genuine "register every provider, THEN boot once" sequence a real production HTTP
 * kernel bootstrap uses — exactly the sequence FireflyServiceProvider's booting()/booted() split
 * depends on (see its class docblock).
 *
 * A FireflyKernel is bound into the container BY HAND before any provider registers — the
 * end-to-end machinery that would build a REAL BootContext (real scan, real conditions, real
 * beans) from a fresh Laravel app is Task 15's job, explicitly out of scope here (M4 task brief).
 * This file only proves FireflyServiceProvider's OWN orchestration: passes() aggregation, the
 * booting/booted split, and the conditional Octane wiring.
 */
final class OctaneProbeMarker {}

final class ProviderBootLog
{
    /** @var list<string> */
    public array $entries = [];

    public function record(string $entry): void
    {
        $this->entries[] = $entry;
    }
}

final class ProviderRecordingPass implements BootPass
{
    public function __construct(
        private readonly BootPhase $recordedPhase,
        private readonly ProviderBootLog $log,
        private readonly string $label,
    ) {}

    public function phase(): BootPhase
    {
        return $this->recordedPhase;
    }

    public function order(): int
    {
        return 0;
    }

    public function run(BootContext $context): void
    {
        $this->log->record($this->label);
    }
}

/**
 * A bare subclass with no overrides at all — proves passes() defaults to [] and register() never
 * fatals even when the subclass contributes nothing.
 */
final class BarePassthroughProvider extends FireflyServiceProvider {}

/**
 * Contributes ONE definition-stage pass (BootPhase::ConfigAndProfiles, value 100) and records its
 * OWN boot() invocation — used to prove the definition-stage pass runs BEFORE this provider's own
 * boot().
 */
final class DefinitionStageProviderStub extends FireflyServiceProvider
{
    public function passes(): array
    {
        return [
            new ProviderRecordingPass(BootPhase::ConfigAndProfiles, $this->app->make(ProviderBootLog::class), 'definition-pass'),
        ];
    }

    public function boot(): void
    {
        $this->app->make(ProviderBootLog::class)->record('definition-stub-boot');
    }
}

/**
 * Contributes ONE instance-stage pass (BootPhase::EagerSingletons, value 900) — used to prove the
 * instance-stage pass runs AFTER every provider's own boot(), including this one's.
 */
final class InstanceStageProviderStub extends FireflyServiceProvider
{
    public function passes(): array
    {
        return [
            new ProviderRecordingPass(BootPhase::EagerSingletons, $this->app->make(ProviderBootLog::class), 'instance-pass'),
        ];
    }

    public function boot(): void
    {
        $this->app->make(ProviderBootLog::class)->record('instance-stub-boot');
    }
}

/**
 * Forces the Octane-absent branch WITHOUT literally uninstalling laravel/octane — see
 * FireflyServiceProvider::octaneIsAvailable()'s docblock for why this seam exists.
 */
final class OctaneAbsentProvider extends FireflyServiceProvider
{
    protected function octaneIsAvailable(): bool
    {
        return false;
    }
}

/**
 * Does NOT override octaneIsAvailable() at all — exposes it publicly so tests can exercise the REAL
 * gate function directly against the real $_SERVER['LARAVEL_OCTANE'] marker, rather than one of the
 * hard-coded [FPM control]/[OCTANE control] stubs below (M4 review #4's decisive finding: both of
 * those stubs override octaneIsAvailable(), so neither one ever exercises the real check).
 */
final class RealGateProbeProvider extends FireflyServiceProvider
{
    public function isOctaneAvailable(): bool
    {
        return $this->octaneIsAvailable();
    }
}

function freshApplication(): Application
{
    return new Application;
}

/**
 * Sets/restores $_SERVER['LARAVEL_OCTANE'] around a test body — the process environment marker
 * Octane's own start commands inject into the server process before PHP even starts (verified
 * against the installed source: laravel/octane v2.17.5's Commands/StartSwooleCommand.php:89,
 * StartRoadRunnerCommand.php:101, StartFrankenPhpCommand.php:96), and the same signal, read the same
 * `isset($_SERVER[...])` way, Laravel's own framework uses for this exact decision
 * (Illuminate\Foundation\Exceptions\Renderer\Listener::registerListeners()). Always restores the
 * PRIOR value (present or absent) afterward, even if the body throws, so no test leaks this global
 * into the rest of the suite (M4 review #4).
 */
function withLaravelOctaneMarker(?string $value, callable $body): void
{
    $original = $_SERVER['LARAVEL_OCTANE'] ?? null;

    if ($value === null) {
        unset($_SERVER['LARAVEL_OCTANE']);
    } else {
        $_SERVER['LARAVEL_OCTANE'] = $value;
    }

    try {
        $body();
    } finally {
        if ($original === null) {
            unset($_SERVER['LARAVEL_OCTANE']);
        } else {
            $_SERVER['LARAVEL_OCTANE'] = $original;
        }
    }
}

function bindFireflyKernel(Container $container, ContextManifest $contextManifest = new ContextManifest([])): FireflyKernel
{
    $config = new Config(new Repository([]));
    $profiles = new Profiles(['test']);

    $context = new BootContext(
        container: $container,
        definitions: new BeanDefinitionRegistry,
        config: $config,
        profiles: $profiles,
        conditions: new ConditionEvaluator($config, $profiles),
        report: new ConditionEvaluationReport,
        contextManifest: $contextManifest,
    );

    $kernel = new FireflyKernel($context);
    $container->instance(FireflyKernel::class, $kernel);

    return $kernel;
}

it('passes() defaults to an empty list', function (): void {
    $app = freshApplication();
    bindFireflyKernel($app);

    $provider = new BarePassthroughProvider($app);

    expect($provider->passes())->toBe([]);
});

it('register() never fatals for a subclass that contributes no passes at all', function (): void {
    $app = freshApplication();
    bindFireflyKernel($app);

    $app->register(BarePassthroughProvider::class);
    $app->boot();

    expect(true)->toBeTrue();
});

it("runs the DEFINITION-stage pass from booting() — BEFORE any provider's own boot()", function (): void {
    $app = freshApplication();
    bindFireflyKernel($app);
    $log = new ProviderBootLog;
    $app->instance(ProviderBootLog::class, $log);

    $app->register(DefinitionStageProviderStub::class);
    $app->boot();

    expect($log->entries)->toBe(['definition-pass', 'definition-stub-boot']);
});

it("runs the INSTANCE-stage pass from booted() — AFTER every provider's own boot()", function (): void {
    $app = freshApplication();
    bindFireflyKernel($app);
    $log = new ProviderBootLog;
    $app->instance(ProviderBootLog::class, $log);

    $app->register(InstanceStageProviderStub::class);
    $app->boot();

    expect($log->entries)->toBe(['instance-stub-boot', 'instance-pass']);
});

it('lets MULTIPLE FireflyServiceProvider subclasses share ONE kernel — the kernel, not registration order, decides PHASE order', function (): void {
    $app = freshApplication();
    bindFireflyKernel($app);
    $log = new ProviderBootLog;
    $app->instance(ProviderBootLog::class, $log);

    // Registered in the OPPOSITE order to how their PHASES should run relative to each other.
    // Laravel still calls each provider's OWN boot() in registration order — that is untouched by
    // (and irrelevant to) the kernel — so this only asserts what the kernel actually guarantees:
    // the definition-stage pass runs before BOTH providers' own boot(), and the instance-stage
    // pass runs after BOTH of them.
    $app->register(InstanceStageProviderStub::class);
    $app->register(DefinitionStageProviderStub::class);
    $app->boot();

    expect($log->entries)->toHaveCount(4)
        ->and($log->entries[0])->toBe('definition-pass')
        ->and($log->entries)->toContain('definition-stub-boot')
        ->and($log->entries)->toContain('instance-stub-boot')
        ->and($log->entries[3])->toBe('instance-pass');
});

it('does not re-run phases on a second boot() call', function (): void {
    $app = freshApplication();
    bindFireflyKernel($app);
    $log = new ProviderBootLog;
    $app->instance(ProviderBootLog::class, $log);

    $app->register(DefinitionStageProviderStub::class);
    $app->boot();
    $app->boot();

    expect($log->entries)->toBe(['definition-pass', 'definition-stub-boot']);
});

// --- ApplicationEventPublisher: the M4 re-review's Critical finding ---

/**
 * The exact documented shape a user application is told to write (see docs/modules/context.md's
 * Events section): a plain #[Component]-shaped class constructor-injecting the
 * ApplicationEventPublisher PORT, never the concrete DispatcherEventPublisher adapter.
 */
final class OrderServiceFixture
{
    public function __construct(public readonly ApplicationEventPublisher $publisher) {}
}

/**
 * Contributes ONLY EagerSingletonsPass — enough to reproduce the exact failure the re-review
 * documented: phase 900 resolves every non-#[Lazy] singleton eagerly, so a missing
 * ApplicationEventPublisher binding aborts boot() itself rather than merely failing at first use.
 */
final class EagerPublisherProviderStub extends FireflyServiceProvider
{
    public function passes(): array
    {
        return [new EagerSingletonsPass];
    }
}

it('resolves a user #[Component]-shaped class injecting ApplicationEventPublisher during EAGER singleton resolution — the documented happy path must not abort boot', function (): void {
    $app = freshApplication();
    $kernel = bindFireflyKernel($app);

    // Hand-registered directly into the BeanDefinitionRegistry — standing in for what a real
    // #[Component] scan would produce (see BootContext's own docblock: this is the same
    // "accept pre-scanned data, do not fake a scan" pattern used throughout this milestone).
    $kernel->context()->definitions->add(new BeanDefinition(new ComponentDescriptor(
        class: OrderServiceFixture::class,
        stereotype: 'Service',
        name: null,
        scope: Scope::Singleton,
        primary: false,
        order: 0,
        qualifier: null,
        interfaces: [],
        beans: [],
    )));

    $app->register(EagerPublisherProviderStub::class);
    $app->boot();

    expect($app->bound(ApplicationEventPublisher::class))->toBeTrue();

    $service = $app->make(OrderServiceFixture::class);
    expect($service)->toBeInstanceOf(OrderServiceFixture::class)
        ->and($service->publisher)->toBeInstanceOf(DispatcherEventPublisher::class);
});

// --- ApplicationContext: singleton identity + genuinely idempotent close() ---

/**
 * Contributes exactly the three passes that bind ApplicationContext's OTHER three collaborators
 * (the FireflyContainer facade, DisposableBeanRegistry, LifecycleRegistry) over an otherwise EMPTY
 * BeanDefinitionRegistry — so that, combined with FireflyServiceProvider's own
 * ApplicationEventPublisher binding, EVERY constructor dependency ApplicationContext needs is
 * genuinely resolvable, and Illuminate's auto-wiring alone (absent the singleton binding this test
 * exists to prove) really would succeed — just with a FRESH instance on every resolution. That is
 * what makes this a discriminating test rather than one that merely fails to resolve at all.
 */
final class MinimalRealPipelineProviderStub extends FireflyServiceProvider
{
    public function passes(): array
    {
        return [
            new FlushDefinitionsPass,
            new RegisterBeanPostProcessorsPass,
            new InfrastructureStartPass,
        ];
    }
}

it('binds ApplicationContext as a SINGLETON — resolving twice returns the SAME instance, and close() is genuinely idempotent', function (): void {
    $app = freshApplication();
    bindFireflyKernel($app);

    $closedCount = 0;
    $app->make(Dispatcher::class)->listen(ContextClosedEvent::class, function () use (&$closedCount): void {
        $closedCount++;
    });

    $app->register(MinimalRealPipelineProviderStub::class);
    $app->boot();

    $first = $app->make(ApplicationContext::class);
    $second = $app->make(ApplicationContext::class);

    expect($first)->toBe($second)
        ->and($first->isActive())->toBeTrue();

    $first->close();
    // Same underlying instance — a second close(), even called through the "other" resolved
    // reference, must be a genuine no-op, not merely coincidentally harmless.
    $second->close();

    expect($closedCount)->toBe(1)
        ->and($first->isActive())->toBeFalse();
});

// --- Shutdown wiring: #[PreDestroy]/Lifecycle::stop() via the REAL application-shutdown hook ---

final class ShutdownProbeState
{
    public bool $poolDisconnected = false;

    public bool $brokerStopped = false;
}

final class ShutdownProbePool
{
    public function __construct(private readonly ShutdownProbeState $state) {}

    #[PreDestroy]
    public function disconnect(): void
    {
        $this->state->poolDisconnected = true;
    }
}

final class ShutdownProbeBroker implements Lifecycle
{
    public function __construct(private readonly ShutdownProbeState $state) {}

    public function start(): void {}

    public function stop(): void
    {
        $this->state->brokerStopped = true;
    }
}

/**
 * The full real instance-stage lifecycle pipeline: FlushDefinitions (binds the classes into the
 * container), BeanPostProcessors (installs #[PreDestroy] tracking), InfrastructureStart (starts the
 * Lifecycle component), EagerSingletons (actually resolves both, since they are Scope::Singleton and
 * not #[Lazy]).
 *
 * Forces octaneIsAvailable() to FALSE, exactly like OctaneAbsentProvider — this is the PHP-FPM half
 * of a one-variable control (see OctaneShutdownPipelineProviderStub below for the other half).
 * CORRECTED CLAIM (M4 review #5, Minor): an earlier version of this comment claimed that without
 * this override, octaneIsAvailable() would "ambiently return true here too" because
 * laravel/octane the package IS installed in this repo. That is FALSE — see the "[REAL GATE]" test
 * below ("...is FALSE when the LARAVEL_OCTANE marker is absent, even though laravel/octane the
 * package IS installed in this repo"), which asserts the exact opposite against the REAL,
 * unstubbed gate. The override is harmless and kept deliberately (a stated, non-relitigated
 * choice — see the class-level test file docblock and M4 review #4), but its actual purpose is:
 * it pins this control's branch independently of the ambient `$_SERVER['LARAVEL_OCTANE']` marker,
 * so this "FPM control" cannot be silently perturbed by a leaked marker value from another test
 * (or from the real process environment) — not because the real gate would otherwise misfire here.
 */
final class ShutdownPipelineProviderStub extends FireflyServiceProvider
{
    public function passes(): array
    {
        return [
            new FlushDefinitionsPass,
            new RegisterBeanPostProcessorsPass,
            new InfrastructureStartPass,
            new EagerSingletonsPass,
        ];
    }

    protected function octaneIsAvailable(): bool
    {
        return false;
    }
}

/**
 * Identical pass list to ShutdownPipelineProviderStub — the ONLY difference is octaneIsAvailable()
 * forced to TRUE. That single-variable difference is what makes the PHP-FPM test above and the
 * Octane-worker test below a genuine one-variable control over the SAME provider/pass/fixture shape.
 */
final class OctaneShutdownPipelineProviderStub extends FireflyServiceProvider
{
    public function passes(): array
    {
        return [
            new FlushDefinitionsPass,
            new RegisterBeanPostProcessorsPass,
            new InfrastructureStartPass,
            new EagerSingletonsPass,
        ];
    }

    protected function octaneIsAvailable(): bool
    {
        return true;
    }
}

/**
 * Identical pass list again — but, critically, does NOT override octaneIsAvailable() at all. This is
 * what lets the "[REAL GATE]" tests below exercise the ACTUAL gate (package presence AND the
 * $_SERVER['LARAVEL_OCTANE'] runtime marker) instead of a hard-coded stub — the exact untested seam
 * M4 review #4 found: both controls above hard-code the gate, so neither ever proves the real
 * function picks the right branch on its own.
 */
final class RealGateShutdownPipelineProviderStub extends FireflyServiceProvider
{
    public function passes(): array
    {
        return [
            new FlushDefinitionsPass,
            new RegisterBeanPostProcessorsPass,
            new InfrastructureStartPass,
            new EagerSingletonsPass,
        ];
    }
}

it('[FPM control] runs #[PreDestroy]/Lifecycle::stop() via the REAL application-shutdown hook — Application::terminate() — not a manual drain', function (): void {
    $app = freshApplication();

    $contextManifest = new ContextManifest([
        new ContextDescriptor(class: ShutdownProbePool::class, preDestroy: ['disconnect']),
    ]);

    $kernel = bindFireflyKernel($app, $contextManifest);
    $kernel->context()->definitions->add(new BeanDefinition(new ComponentDescriptor(
        class: ShutdownProbePool::class,
        stereotype: 'Service',
        name: null,
        scope: Scope::Singleton,
        primary: false,
        order: 0,
        qualifier: null,
        interfaces: [],
        beans: [],
    )));
    $kernel->context()->definitions->add(new BeanDefinition(new ComponentDescriptor(
        class: ShutdownProbeBroker::class,
        stereotype: 'Service',
        name: null,
        scope: Scope::Singleton,
        primary: false,
        order: 0,
        qualifier: null,
        interfaces: [Lifecycle::class],
        beans: [],
    )));

    $app->singleton(ShutdownProbeState::class);

    $app->register(ShutdownPipelineProviderStub::class);
    $app->boot();

    /** @var ShutdownProbeState $state */
    $state = $app->make(ShutdownProbeState::class);

    expect($state->poolDisconnected)->toBeFalse()
        ->and($state->brokerStopped)->toBeFalse();

    // THE REAL shutdown path FOR PHP-FPM (this test's runtime, via octaneIsAvailable()=false above):
    // Illuminate\Foundation\Application::terminate() — the same method
    // Illuminate\Foundation\Http\Kernel::terminate() calls at the end of every PHP-FPM request
    // (public/index.php's `$kernel->terminate($request, $response)`). Deliberately NOT
    // $applicationContext->close() called directly — that would only prove close()'s own ordering
    // (already covered elsewhere), not that a real application actually triggers it, which is
    // precisely the M4 re-review's Important finding: FireflyKernel::boot() had zero production
    // callers, so nothing ever closed the context in a real application.
    //
    // CORRECTED CLAIM: an earlier version of this comment additionally claimed this test also
    // exercises Octane's shutdown path via "Laravel\Octane\ApplicationGateway::terminate() ... through
    // the request's sandboxed Kernel" — that claim was FALSE. This test builds and terminates ONE
    // application ONCE; it never clones a sandbox and never serves a second request, so it cannot
    // discriminate the Octane per-request-teardown bug the M4 review #3 found (terminating() firing
    // at the end of EVERY Octane request, not once per worker). This is the FPM half of a
    // one-variable control; see the "[OCTANE control]" tests below for the runtime-varying half that
    // this test's own comment used to falsely claim to cover.
    $app->terminate();

    expect($state->poolDisconnected)->toBeTrue()
        ->and($state->brokerStopped)->toBeTrue();
});

it('[OCTANE control] does NOT close the context at the end of request #1 — singleton #[PreDestroy]/Lifecycle::stop() must survive across requests within one worker', function (): void {
    $app = freshApplication();

    $contextManifest = new ContextManifest([
        new ContextDescriptor(class: ShutdownProbePool::class, preDestroy: ['disconnect']),
    ]);

    $kernel = bindFireflyKernel($app, $contextManifest);
    $kernel->context()->definitions->add(new BeanDefinition(new ComponentDescriptor(
        class: ShutdownProbePool::class,
        stereotype: 'Service',
        name: null,
        scope: Scope::Singleton,
        primary: false,
        order: 0,
        qualifier: null,
        interfaces: [],
        beans: [],
    )));
    $kernel->context()->definitions->add(new BeanDefinition(new ComponentDescriptor(
        class: ShutdownProbeBroker::class,
        stereotype: 'Service',
        name: null,
        scope: Scope::Singleton,
        primary: false,
        order: 0,
        qualifier: null,
        interfaces: [Lifecycle::class],
        beans: [],
    )));

    $app->singleton(ShutdownProbeState::class);

    // The WORKER application boots exactly ONCE — Octane's real shape (Laravel\Octane\Worker::boot()
    // runs the framework's booting/booted bootstrappers a single time for the whole worker process).
    $app->register(OctaneShutdownPipelineProviderStub::class);
    $app->boot();

    /** @var ShutdownProbeState $state */
    $state = $app->make(ShutdownProbeState::class);

    expect($state->poolDisconnected)->toBeFalse()
        ->and($state->brokerStopped)->toBeFalse();

    $pool = $app->make(ShutdownProbePool::class);

    // --- Request #1: Octane's REAL per-request shape (Laravel\Octane\Worker::handle() +
    // Laravel\Octane\ApplicationGateway::terminate()) — clone the worker app into a per-request
    // sandbox and terminate THAT SANDBOX, never the worker app itself. A clone copies the worker's
    // $terminatingCallbacks array by value, so this is exactly what would carry the bug forward if
    // ApplicationContext::close() were (still, wrongly) wired to $app->terminating().
    $sandbox1 = clone $app;
    $sandbox1->terminate();

    // THE regression this test exists to catch: request #1 ending must NOT close the worker-lifetime
    // ApplicationContext. Under the pre-fix code (close() wired unconditionally to terminating()),
    // this fails: poolDisconnected/brokerStopped both flip true here, one request into the worker's
    // life.
    expect($state->poolDisconnected)->toBeFalse()
        ->and($state->brokerStopped)->toBeFalse()
        ->and($app->make(ApplicationContext::class)->isActive())->toBeTrue();

    // --- Request #2 must be served by the SAME, still-live singleton — not a torn-down one. ---
    $sandbox2 = clone $app;
    expect($sandbox2->make(ShutdownProbePool::class))->toBe($pool);
    $sandbox2->terminate();

    expect($state->poolDisconnected)->toBeFalse()
        ->and($state->brokerStopped)->toBeFalse();

    // --- Only Octane's real once-per-worker shutdown event closes the context. ---
    $app->make(Dispatcher::class)->dispatch(new WorkerStopping($app));

    expect($state->poolDisconnected)->toBeTrue()
        ->and($state->brokerStopped)->toBeTrue()
        ->and($app->make(ApplicationContext::class)->isActive())->toBeFalse();
});

// --- M4 review #5 (Important, disclosed not fixed): a #[Lazy] singleton's #[PreDestroy] does NOT
// run under Octane when it is first resolved INSIDE a request (into the per-request sandbox) rather
// than eagerly, at worker boot (into the worker itself). One-variable control: same worker, same
// pass list, only #[Lazy] differs between the two probe beans below — plus a PHP-FPM control
// proving the SAME lazy bean class behaves correctly outside Octane. See DisposableBeanRegistry's
// own docblock and docs/modules/context.md's Lifecycle/Octane sections for the disclosed contract.

final class LazyPreDestroyProbeState
{
    public bool $eagerDisconnected = false;

    public bool $lazyDisconnected = false;
}

final class EagerPreDestroyProbeBean
{
    public function __construct(private readonly LazyPreDestroyProbeState $state) {}

    #[PreDestroy]
    public function disconnect(): void
    {
        $this->state->eagerDisconnected = true;
    }
}

final class LazyPreDestroyProbeBean
{
    public function __construct(private readonly LazyPreDestroyProbeState $state) {}

    #[PreDestroy]
    public function disconnect(): void
    {
        $this->state->lazyDisconnected = true;
    }
}

it('[OCTANE] EAGER singleton #[PreDestroy] FIRES at WorkerStopping, but the IDENTICAL-SHAPED #[Lazy] singleton first resolved inside a request NEVER fires — same worker, only #[Lazy] differs', function () {
    $app = freshApplication();

    $contextManifest = new ContextManifest([
        new ContextDescriptor(class: EagerPreDestroyProbeBean::class, preDestroy: ['disconnect']),
        new ContextDescriptor(class: LazyPreDestroyProbeBean::class, preDestroy: ['disconnect']),
    ]);

    $kernel = bindFireflyKernel($app, $contextManifest);
    $kernel->context()->definitions->add(new BeanDefinition(new ComponentDescriptor(
        class: EagerPreDestroyProbeBean::class,
        stereotype: 'Service',
        name: null,
        scope: Scope::Singleton,
        primary: false,
        order: 0,
        qualifier: null,
        interfaces: [],
        beans: [],
        lazy: false,
    )));
    $kernel->context()->definitions->add(new BeanDefinition(new ComponentDescriptor(
        class: LazyPreDestroyProbeBean::class,
        stereotype: 'Service',
        name: null,
        scope: Scope::Singleton,
        primary: false,
        order: 0,
        qualifier: null,
        interfaces: [],
        beans: [],
        lazy: true,
    )));

    $app->singleton(LazyPreDestroyProbeState::class);

    // Reuses the existing OCTANE-forced provider stub (identical pass list to the [OCTANE control]
    // test above) — the worker boots exactly once.
    $app->register(OctaneShutdownPipelineProviderStub::class);
    $app->boot();

    /** @var LazyPreDestroyProbeState $state */
    $state = $app->make(LazyPreDestroyProbeState::class);

    // EagerSingletonsPass (phase 900, part of boot()) already resolved the non-#[Lazy] bean INTO
    // THE WORKER — before any sandbox exists. The #[Lazy] bean was skipped entirely; nothing has
    // resolved it yet.
    expect($state->eagerDisconnected)->toBeFalse()
        ->and($state->lazyDisconnected)->toBeFalse();

    // --- Request #1: Octane's real per-request shape — clone the worker into a sandbox, and the
    // #[Lazy] bean is resolved for the FIRST TIME here, INSIDE the sandbox, not the worker.
    $sandbox1 = clone $app;
    $sandbox1->make(LazyPreDestroyProbeBean::class);

    // End of request #1: Octane flushes the sandbox (clears ITS OWN bindings/instances — the only
    // strong reference to the bean just built) and discards it. Mirrors
    // DisposableBeanRegistryTest's own "\WeakReference: a garbage-collected bean is silently
    // skipped" pattern, over the REAL Octane clone/flush shape instead of a bare unset().
    $sandbox1->flush();
    unset($sandbox1);
    gc_collect_cycles();

    // --- Only the real once-per-worker shutdown event closes the context. ---
    $app->make(Dispatcher::class)->dispatch(new WorkerStopping($app));

    // THE DISCLOSED GAP: the eager bean's #[PreDestroy] fires (the worker itself has held a strong
    // reference to it since boot); the lazy bean's does not — its only strong reference died with
    // sandbox1, long before this drain ever ran, so DisposableBeanRegistry's WeakReference is
    // already dead and silently skipped.
    expect($state->eagerDisconnected)->toBeTrue()
        ->and($state->lazyDisconnected)->toBeFalse();
});

it('[FPM control] the SAME #[Lazy] singleton class DOES have its #[PreDestroy] fire under PHP-FPM — the gap above is Octane-specific, not a #[Lazy] defect in general', function () {
    $app = freshApplication();

    $contextManifest = new ContextManifest([
        new ContextDescriptor(class: LazyPreDestroyProbeBean::class, preDestroy: ['disconnect']),
    ]);

    $kernel = bindFireflyKernel($app, $contextManifest);
    $kernel->context()->definitions->add(new BeanDefinition(new ComponentDescriptor(
        class: LazyPreDestroyProbeBean::class,
        stereotype: 'Service',
        name: null,
        scope: Scope::Singleton,
        primary: false,
        order: 0,
        qualifier: null,
        interfaces: [],
        beans: [],
        lazy: true,
    )));

    $app->singleton(LazyPreDestroyProbeState::class);

    // Reuses the existing FPM-forced provider stub (octaneIsAvailable() = false).
    $app->register(ShutdownPipelineProviderStub::class);
    $app->boot();

    /** @var LazyPreDestroyProbeState $state */
    $state = $app->make(LazyPreDestroyProbeState::class);
    expect($state->lazyDisconnected)->toBeFalse();

    // No sandbox at all under PHP-FPM: the SAME application both resolves the lazy bean and later
    // terminates — the resolving container and the terminating one are the same object, so the
    // strong reference the container itself holds survives all the way to terminate().
    $app->make(LazyPreDestroyProbeBean::class);
    $app->terminate();

    expect($state->lazyDisconnected)->toBeTrue();
});

// --- [REAL GATE] M4 review #4 (Important): octaneIsAvailable() itself, unstubbed, against the real
// $_SERVER['LARAVEL_OCTANE'] marker. The two controls above both HARD-CODE octaneIsAvailable(), so
// neither one exercises the actual gate — this is the untested seam the regression shipped through.

it('the REAL octaneIsAvailable() gate is TRUE when the LARAVEL_OCTANE marker is set', function (): void {
    withLaravelOctaneMarker('1', function (): void {
        $provider = new RealGateProbeProvider(freshApplication());

        expect($provider->isOctaneAvailable())->toBeTrue();
    });
});

it('the REAL octaneIsAvailable() gate is FALSE when the LARAVEL_OCTANE marker is absent, even though laravel/octane the package IS installed in this repo', function (): void {
    withLaravelOctaneMarker(null, function (): void {
        $provider = new RealGateProbeProvider(freshApplication());

        expect($provider->isOctaneAvailable())->toBeFalse();
    });
});

it('[REAL GATE] octane package present but NO worker running (an artisan/queue-style process) — terminating() still fires #[PreDestroy]/Lifecycle::stop() — proves the M4 review #4 regression is closed', function (): void {
    withLaravelOctaneMarker(null, function (): void {
        $app = freshApplication();

        $contextManifest = new ContextManifest([
            new ContextDescriptor(class: ShutdownProbePool::class, preDestroy: ['disconnect']),
        ]);

        $kernel = bindFireflyKernel($app, $contextManifest);
        $kernel->context()->definitions->add(new BeanDefinition(new ComponentDescriptor(
            class: ShutdownProbePool::class,
            stereotype: 'Service',
            name: null,
            scope: Scope::Singleton,
            primary: false,
            order: 0,
            qualifier: null,
            interfaces: [],
            beans: [],
        )));
        $kernel->context()->definitions->add(new BeanDefinition(new ComponentDescriptor(
            class: ShutdownProbeBroker::class,
            stereotype: 'Service',
            name: null,
            scope: Scope::Singleton,
            primary: false,
            order: 0,
            qualifier: null,
            interfaces: [Lifecycle::class],
            beans: [],
        )));

        $app->singleton(ShutdownProbeState::class);

        $app->register(RealGateShutdownPipelineProviderStub::class);
        $app->boot();

        /** @var ShutdownProbeState $state */
        $state = $app->make(ShutdownProbeState::class);

        expect($state->poolDisconnected)->toBeFalse()
            ->and($state->brokerStopped)->toBeFalse();

        // No worker, no sandbox — a plain artisan/queue-style process ending the ordinary way.
        // Pre-fix, octaneIsAvailable() returned true purely because laravel/octane is installed (a
        // dev dependency of THIS package), so close() was wired to WorkerStopping — an event nothing
        // in this process ever dispatches — and #[PreDestroy]/Lifecycle::stop() NEVER ran here.
        $app->terminate();

        expect($state->poolDisconnected)->toBeTrue()
            ->and($state->brokerStopped)->toBeTrue();
    });
});

it('[REAL GATE] octane package present AND the LARAVEL_OCTANE marker set — a mid-worker request terminate() must NOT close; only the real WorkerStopping event does', function (): void {
    withLaravelOctaneMarker('1', function (): void {
        $app = freshApplication();

        $contextManifest = new ContextManifest([
            new ContextDescriptor(class: ShutdownProbePool::class, preDestroy: ['disconnect']),
        ]);

        $kernel = bindFireflyKernel($app, $contextManifest);
        $kernel->context()->definitions->add(new BeanDefinition(new ComponentDescriptor(
            class: ShutdownProbePool::class,
            stereotype: 'Service',
            name: null,
            scope: Scope::Singleton,
            primary: false,
            order: 0,
            qualifier: null,
            interfaces: [],
            beans: [],
        )));
        $kernel->context()->definitions->add(new BeanDefinition(new ComponentDescriptor(
            class: ShutdownProbeBroker::class,
            stereotype: 'Service',
            name: null,
            scope: Scope::Singleton,
            primary: false,
            order: 0,
            qualifier: null,
            interfaces: [Lifecycle::class],
            beans: [],
        )));

        $app->singleton(ShutdownProbeState::class);

        $app->register(RealGateShutdownPipelineProviderStub::class);
        $app->boot();

        /** @var ShutdownProbeState $state */
        $state = $app->make(ShutdownProbeState::class);

        // The real per-request shape: clone the worker into a sandbox and terminate THAT — must NOT
        // close the worker-lifetime context.
        $sandbox = clone $app;
        $sandbox->terminate();

        expect($state->poolDisconnected)->toBeFalse()
            ->and($state->brokerStopped)->toBeFalse();

        // Only the real once-per-worker shutdown event closes the context.
        $app->make(Dispatcher::class)->dispatch(new WorkerStopping($app));

        expect($state->poolDisconnected)->toBeTrue()
            ->and($state->brokerStopped)->toBeTrue();
    });
});

// --- Octane wiring: conditional on presence, must never fatal in its absence ---

it('wires OctaneListener when Octane IS available — a real RequestReceived event resets scoped state', function (): void {
    withLaravelOctaneMarker('1', function (): void {
        $app = freshApplication();
        bindFireflyKernel($app);

        $app->register(BarePassthroughProvider::class);
        $app->boot();

        expect($app->bound(OctaneListener::class))->toBeTrue();

        $sandbox = clone $app;
        $sandbox->scoped(OctaneProbeMarker::class, static fn (): OctaneProbeMarker => new OctaneProbeMarker);
        $probe = $sandbox->make(OctaneProbeMarker::class);

        $app->make(Dispatcher::class)->dispatch(new RequestReceived($app, $sandbox, Request::create('/')));

        $rebuilt = $sandbox->make(OctaneProbeMarker::class);
        expect($rebuilt)->not->toBe($probe);
    });
});

it('does NOT wire OctaneListener, and does NOT fatal, when Octane is absent', function (): void {
    $app = freshApplication();
    bindFireflyKernel($app);

    $app->register(OctaneAbsentProvider::class);
    $app->boot();

    expect($app->bound(OctaneListener::class))->toBeFalse();
});
