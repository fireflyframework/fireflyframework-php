<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Config\Profile\Profiles;
use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;
use Firefly\Context\Boot\FireflyKernel;
use Firefly\Context\Boot\FireflyServiceProvider;
use Firefly\Context\Condition\ConditionEvaluationReport;
use Firefly\Context\Condition\ConditionEvaluator;
use Firefly\Context\Definition\BeanDefinitionRegistry;
use Firefly\Context\Octane\OctaneListener;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Laravel\Octane\Events\RequestReceived;

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

function freshApplication(): Application
{
    return new Application;
}

function bindFireflyKernel(Container $container): FireflyKernel
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

// --- Octane wiring: conditional on presence, must never fatal in its absence ---

it('wires OctaneListener when Octane IS available — a real RequestReceived event resets scoped state', function (): void {
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

it('does NOT wire OctaneListener, and does NOT fatal, when Octane is absent', function (): void {
    $app = freshApplication();
    bindFireflyKernel($app);

    $app->register(OctaneAbsentProvider::class);
    $app->boot();

    expect($app->bound(OctaneListener::class))->toBeFalse();
});
