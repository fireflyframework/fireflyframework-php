<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Config\Profile\Profiles;
use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;
use Firefly\Context\Boot\FireflyKernel;
use Firefly\Context\Condition\ConditionEvaluationReport;
use Firefly\Context\Condition\ConditionEvaluator;
use Firefly\Context\Definition\BeanDefinitionRegistry;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;

/**
 * FireflyKernel is exercised through REAL BootPass implementations (never mocks of our own
 * interfaces) so the assertions cover the actual sort/dispatch logic, not a stand-in for it.
 */

/**
 * A shared recorder: fixture passes append a label when run() executes, so a test can assert both
 * WHICH passes ran and in WHAT order without touching the container.
 */
final class BootLog
{
    /** @var list<string> */
    public array $entries = [];

    public function record(string $entry): void
    {
        $this->entries[] = $entry;
    }
}

/**
 * A generic recording pass: phase/order are supplied by the test, so one fixture class stands in
 * for many differently-ordered passes.
 */
final class RecordingBootPass implements BootPass
{
    public function __construct(
        private readonly BootPhase $recordedPhase,
        private readonly int $recordedOrder,
        private readonly BootLog $log,
        private readonly string $label,
    ) {}

    public function phase(): BootPhase
    {
        return $this->recordedPhase;
    }

    public function order(): int
    {
        return $this->recordedOrder;
    }

    public function run(BootContext $context): void
    {
        $this->log->record($this->label);
    }
}

/**
 * Three passes sharing the SAME phase and the SAME order() — the only thing distinguishing them is
 * their FQCN. Used to prove requirement 1: (phase, order, FQCN) is a TOTAL order, so array insertion
 * order can never leak into the resolved boot plan. Each records ITS OWN class name so the test can
 * assert the actual resolved sequence, not an indirect proxy for it.
 */
final class AlphaTieBreakPass implements BootPass
{
    public function __construct(private readonly BootLog $log) {}

    public function phase(): BootPhase
    {
        return BootPhase::UserConfigurations;
    }

    public function order(): int
    {
        return 42;
    }

    public function run(BootContext $context): void
    {
        $this->log->record(self::class);
    }
}

final class BravoTieBreakPass implements BootPass
{
    public function __construct(private readonly BootLog $log) {}

    public function phase(): BootPhase
    {
        return BootPhase::UserConfigurations;
    }

    public function order(): int
    {
        return 42;
    }

    public function run(BootContext $context): void
    {
        $this->log->record(self::class);
    }
}

final class CharlieTieBreakPass implements BootPass
{
    public function __construct(private readonly BootLog $log) {}

    public function phase(): BootPhase
    {
        return BootPhase::UserConfigurations;
    }

    public function order(): int
    {
        return 42;
    }

    public function run(BootContext $context): void
    {
        $this->log->record(self::class);
    }
}

function makeBootContext(): BootContext
{
    $config = new Config(new Repository([]));
    $profiles = new Profiles(['test']);

    return new BootContext(
        container: new Container,
        definitions: new BeanDefinitionRegistry,
        config: $config,
        profiles: $profiles,
        conditions: new ConditionEvaluator($config, $profiles),
        report: new ConditionEvaluationReport,
    );
}

// --- requirement 1: deterministic total ordering — (phase->value, order(), FQCN) ---

it('runs passes in phase order regardless of the order they were added', function () {
    $log = new BootLog;
    $kernel = new FireflyKernel(makeBootContext());

    // Added deliberately out of phase order.
    $kernel->addPass(new RecordingBootPass(BootPhase::ContextRefreshed, 0, $log, 'refreshed'))
        ->addPass(new RecordingBootPass(BootPhase::ConfigAndProfiles, 0, $log, 'config'))
        ->addPass(new RecordingBootPass(BootPhase::EagerSingletons, 0, $log, 'eager'));

    $kernel->boot();

    expect($log->entries)->toBe(['config', 'eager', 'refreshed']);
});

it('runs passes within one phase in ascending order() regardless of insertion order', function () {
    $log = new BootLog;
    $kernel = new FireflyKernel(makeBootContext());

    $kernel->addPass(new RecordingBootPass(BootPhase::UserConfigurations, 30, $log, 'third'))
        ->addPass(new RecordingBootPass(BootPhase::UserConfigurations, 10, $log, 'first'))
        ->addPass(new RecordingBootPass(BootPhase::UserConfigurations, 20, $log, 'second'));

    $kernel->boot();

    expect($log->entries)->toBe(['first', 'second', 'third']);
});

it('breaks a same-phase, same-order() tie by FQCN, stably across two different insertion orders', function () {
    $expected = [AlphaTieBreakPass::class, BravoTieBreakPass::class, CharlieTieBreakPass::class];

    $logOne = new BootLog;
    $kernelOne = new FireflyKernel(makeBootContext());
    $kernelOne->addPass(new CharlieTieBreakPass($logOne))
        ->addPass(new AlphaTieBreakPass($logOne))
        ->addPass(new BravoTieBreakPass($logOne));
    $kernelOne->boot();

    $logTwo = new BootLog;
    $kernelTwo = new FireflyKernel(makeBootContext());
    $kernelTwo->addPass(new BravoTieBreakPass($logTwo))
        ->addPass(new CharlieTieBreakPass($logTwo))
        ->addPass(new AlphaTieBreakPass($logTwo));
    $kernelTwo->boot();

    expect($logOne->entries)->toBe($expected)
        ->and($logTwo->entries)->toBe($expected);
});

// --- run(BootPhase ...$phases) runs only the named phases ---

it("run(BootPhase) executes only that phase's passes", function () {
    $log = new BootLog;
    $kernel = new FireflyKernel(makeBootContext());

    $kernel->addPass(new RecordingBootPass(BootPhase::ConfigAndProfiles, 0, $log, 'config'))
        ->addPass(new RecordingBootPass(BootPhase::EagerSingletons, 0, $log, 'eager'));

    $kernel->run(BootPhase::ConfigAndProfiles);

    expect($log->entries)->toBe(['config']);
});

// --- requirement 2: run() is idempotent PER PHASE ---

it("run(X); run(X); executes X's passes exactly once", function () {
    $log = new BootLog;
    $kernel = new FireflyKernel(makeBootContext());
    $kernel->addPass(new RecordingBootPass(BootPhase::ConfigAndProfiles, 0, $log, 'config'));

    $kernel->run(BootPhase::ConfigAndProfiles);
    $kernel->run(BootPhase::ConfigAndProfiles);

    expect($log->entries)->toBe(['config']);
});

it('run(X); boot(); does NOT re-run X — boot() and run() share completion bookkeeping', function () {
    $log = new BootLog;
    $kernel = new FireflyKernel(makeBootContext());
    $kernel->addPass(new RecordingBootPass(BootPhase::ConfigAndProfiles, 0, $log, 'config'))
        ->addPass(new RecordingBootPass(BootPhase::EagerSingletons, 0, $log, 'eager'));

    $kernel->run(BootPhase::ConfigAndProfiles);
    $kernel->boot();

    expect($log->entries)->toBe(['config', 'eager']);
});

// --- the extension seam: a phase with exactly one contributed pass still runs ---

it('a pass added for a phase with no other contributed passes still runs', function () {
    $log = new BootLog;
    $kernel = new FireflyKernel(makeBootContext());
    $kernel->addPass(new RecordingBootPass(BootPhase::WiringPasses, 0, $log, 'wiring'));

    $kernel->boot();

    expect($log->entries)->toBe(['wiring']);
});

// --- boot() runs every contributed pass exactly once, in full pipeline order ---

it('boot() runs every contributed pass exactly once, in full pipeline order', function () {
    $log = new BootLog;
    $kernel = new FireflyKernel(makeBootContext());

    $kernel->addPass(new RecordingBootPass(BootPhase::EagerSingletons, 0, $log, 'eager'))
        ->addPass(new RecordingBootPass(BootPhase::ConfigAndProfiles, 0, $log, 'config'))
        ->addPass(new RecordingBootPass(BootPhase::ContextRefreshed, 0, $log, 'refreshed'))
        ->addPass(new RecordingBootPass(BootPhase::UserConfigurations, 0, $log, 'user'));

    $kernel->boot();
    $kernel->boot(); // a second boot() must be a no-op, not a double-run

    expect($log->entries)->toBe(['config', 'user', 'eager', 'refreshed']);
});

// --- context() accessor ---

it('context() returns the exact BootContext the kernel was constructed with', function () {
    $context = makeBootContext();
    $kernel = new FireflyKernel($context);

    expect($kernel->context())->toBe($context);
});
