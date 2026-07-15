<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Config\Profile\Profiles;
use Firefly\Container\Descriptor\ComponentDescriptor;
use Firefly\Container\Scope;
use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;
use Firefly\Context\Boot\FireflyKernel;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Context\Condition\ConditionEvaluationReport;
use Firefly\Context\Condition\ConditionEvaluator;
use Firefly\Context\Definition\BeanDefinition;
use Firefly\Context\Definition\BeanDefinitionRegistry;
use Firefly\Context\Definition\DefinitionSource;
use Firefly\Context\Pass\ConditionPassOnePass;
use Firefly\Context\Pass\ConditionPassTwoPass;
use Firefly\Context\Pass\FlushDefinitionsPass;
use Firefly\Context\Pass\UserConfigurationsPass;
use Firefly\Context\Tests\Fixtures\Cache;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;

/**
 * FOCUSED regression tests for the M4 condition-phase-ordering bug (see BootPhase's class
 * docblock), run through the REAL composed FireflyKernel pipeline — real BootPass implementations,
 * dispatched by the kernel's own BootPhase ordinals — and NEVER a hand-built registry the test
 * pre-populates itself.
 *
 * That distinction is the entire point: every per-pass unit test in tests/Pass/*PassTest.php
 * exercises ONE pass, in isolation, against a registry the test built by hand — exactly how the
 * original bug hid. Each pass, tested alone, "just worked" against a registry that already
 * happened to contain what it needed; only composing the passes through the kernel, in the
 * kernel's own phase order, could ever have caught a pass running on the wrong side of its
 * definition source. This file is the lighter-weight (hand-built ComponentDescriptor, no real
 * scanning/manifest compilation) sibling of packages/context/tests/IntegrationTest.php's capstone
 * coverage of the same guarantee.
 */

/**
 * @param  array<string, mixed>  $items
 */
function phaseOrderingConfig(array $items = []): Config
{
    return new Config(new Repository($items));
}

/**
 * @param  list<class-string>  $interfaces
 */
function phaseOrderingDescriptor(string $class, array $interfaces = []): ComponentDescriptor
{
    return new ComponentDescriptor(
        class: $class,
        stereotype: 'Service',
        name: null,
        scope: Scope::Singleton,
        primary: false,
        order: 0,
        qualifier: null,
        interfaces: $interfaces,
        beans: [],
    );
}

/**
 * @param  array<string, mixed>  $configItems
 */
function phaseOrderingBootContext(array $configItems = []): BootContext
{
    $config = phaseOrderingConfig($configItems);
    $profiles = new Profiles([]);

    return new BootContext(
        container: new Container,
        definitions: new BeanDefinitionRegistry,
        config: $config,
        profiles: $profiles,
        conditions: new ConditionEvaluator($config, $profiles),
        report: new ConditionEvaluationReport,
    );
}

/**
 * A test-local stand-in for M5's real AutoConfigurations pass: adds a fixed set of
 * DefinitionSource::AutoConfiguration BeanDefinitions to the registry, running at
 * BootPhase::AutoConfigurations exactly like a real starter's auto-configuration pass eventually
 * will. M4 ships no AutoConfigDiscovery/AutoConfigurations pass yet, so without a fixture like this
 * there would be no way to prove that a definition ADDED AT ITS OWN PHASE (rather than pre-seeded
 * into the registry before boot() even starts, which would sidestep the very ordering this test
 * exists to lock down) has its conditions evaluated at all.
 */
final class ConditionPhaseOrderingAutoConfigPass implements BootPass
{
    /**
     * @param  list<BeanDefinition>  $definitions
     */
    public function __construct(private readonly array $definitions) {}

    public function phase(): BootPhase
    {
        return BootPhase::AutoConfigurations;
    }

    public function order(): int
    {
        return 0;
    }

    public function run(BootContext $context): void
    {
        foreach ($this->definitions as $definition) {
            $context->definitions->add($definition);
        }
    }
}

// --- Regression #1: a User #[ConditionalOnProperty] mismatch is NOT registered ---
// (packages/context/tests/IntegrationTest.php already proves this through the full scanned +
// compiled manifest path; this is the focused, composed-pipeline-but-hand-built-descriptor version
// the task brief asks for explicitly.)

it('removes a User definition whose #[ConditionalOnProperty] does not match — through the REAL composed pipeline', function () {
    // 'firefly.feature.off' is deliberately never set.
    $bootContext = phaseOrderingBootContext();
    $kernel = new FireflyKernel($bootContext);

    $kept = new BeanDefinition(phaseOrderingDescriptor('App\Kept'));
    $removed = new BeanDefinition(
        phaseOrderingDescriptor('App\Removed'),
        conditions: [new ConditionalOnProperty('firefly.feature.off')],
    );

    $kernel->addPass(new UserConfigurationsPass([$kept, $removed]))
        ->addPass(new ConditionPassOnePass)
        ->addPass(new ConditionPassTwoPass)
        ->addPass(new FlushDefinitionsPass);

    $kernel->boot();

    $survivingClasses = array_map(
        static fn (BeanDefinition $d): string => $d->class(),
        $bootContext->definitions->all(),
    );

    expect($survivingClasses)->toBe(['App\Kept'])
        ->and($bootContext->container->bound('App\Kept'))->toBeTrue()
        ->and($bootContext->container->bound('App\Removed'))->toBeFalse();
});

// --- Regression #2: an AutoConfiguration definition's REGISTRY-INDEPENDENT condition IS
// evaluated — the LATENT bug (would have broken every M5 starter). MUST fail against the
// pre-fix ordinals (verified manually via fault injection — see the task report).

it('removes an AutoConfiguration definition whose #[ConditionalOnProperty] does not match — the latent-bug regression', function () {
    $bootContext = phaseOrderingBootContext(['firefly' => ['autoconfig' => ['on' => false]]]);
    $kernel = new FireflyKernel($bootContext);

    $autoConfig = new BeanDefinition(
        phaseOrderingDescriptor('App\AutoAlwaysGated'),
        conditions: [new ConditionalOnProperty('firefly.autoconfig.on', havingValue: 'true')],
        source: DefinitionSource::AutoConfiguration,
    );

    // Added deliberately in a non-pipeline order — the kernel, not addPass() call order, decides
    // when each pass actually runs (see FireflyKernel's own docblock).
    $kernel->addPass(new ConditionPassTwoPass)
        ->addPass(new ConditionPhaseOrderingAutoConfigPass([$autoConfig]))
        ->addPass(new ConditionPassOnePass)
        ->addPass(new UserConfigurationsPass([]))
        ->addPass(new FlushDefinitionsPass);

    $kernel->boot();

    expect($bootContext->definitions->all())->toBe([])
        ->and($bootContext->container->bound('App\AutoAlwaysGated'))->toBeFalse();

    $entries = $bootContext->report->all();
    expect($entries)->toHaveCount(1)
        ->and($entries[0]['class'])->toBe('App\AutoAlwaysGated')
        ->and($entries[0]['attribute'])->toBe(ConditionalOnProperty::class)
        ->and($entries[0]['outcome']->matched)->toBeFalse();
});

// --- Regression #3: the #[ConditionalOnMissingBean] starter mechanism, end-to-end ---
//
// NOTE ON FIXTURE SHAPE: the AutoConfiguration candidate in the next two tests deliberately does
// NOT declare `interfaces: [Cache::class]` on its OWN descriptor, even though conceptually it "is"
// a Cache fallback. This isolates the ONE thing these two tests exist to prove: that
// ConditionPassTwoPass evaluates an AutoConfiguration's bean condition against the real,
// user-filtered registry — backing off when ANOTHER definition supplies the type, and applying
// when nothing else does.
//
// A THIRD scenario — where the candidate's OWN descriptor ALSO declares the interface it is
// gated on (the "self-seeing" shape) — used to be a known, unfixed gap: BeanDefinitionRegistry::
// containsType() does not exclude the definition currently being evaluated, so a batch-filtering
// ConditionPassTwoPass would find the candidate's own contribution and always back off from
// itself, regardless of whether any OTHER definition provided the type. ConditionPassTwoPass no
// longer batch-filters — see its class docblock for the incremental (order, FQCN) model that
// fixes this — so that scenario is now covered explicitly below, through the same real composed
// FireflyKernel pipeline, rather than deliberately avoided.

it("an AutoConfiguration's #[ConditionalOnMissingBean] backs off when a user bean supplies the type", function () {
    $bootContext = phaseOrderingBootContext();
    $kernel = new FireflyKernel($bootContext);

    $userCache = new BeanDefinition(phaseOrderingDescriptor('App\UserCache', interfaces: [Cache::class]));
    $autoConfig = new BeanDefinition(
        phaseOrderingDescriptor('App\DefaultCache'),
        conditions: [new ConditionalOnMissingBean(Cache::class)],
        source: DefinitionSource::AutoConfiguration,
    );

    $kernel->addPass(new UserConfigurationsPass([$userCache]))
        ->addPass(new ConditionPassOnePass)
        ->addPass(new ConditionPhaseOrderingAutoConfigPass([$autoConfig]))
        ->addPass(new ConditionPassTwoPass)
        ->addPass(new FlushDefinitionsPass);

    $kernel->boot();

    $survivingClasses = array_map(
        static fn (BeanDefinition $d): string => $d->class(),
        $bootContext->definitions->all(),
    );

    expect($survivingClasses)->toBe(['App\UserCache'])
        ->and($bootContext->container->bound('App\UserCache'))->toBeTrue()
        ->and($bootContext->container->bound('App\DefaultCache'))->toBeFalse();
});

it("an AutoConfiguration's #[ConditionalOnMissingBean] applies when no user bean supplies the type", function () {
    $bootContext = phaseOrderingBootContext();
    $kernel = new FireflyKernel($bootContext);

    $autoConfig = new BeanDefinition(
        phaseOrderingDescriptor('App\DefaultCache'),
        conditions: [new ConditionalOnMissingBean(Cache::class)],
        source: DefinitionSource::AutoConfiguration,
    );

    $kernel->addPass(new UserConfigurationsPass([]))
        ->addPass(new ConditionPassOnePass)
        ->addPass(new ConditionPhaseOrderingAutoConfigPass([$autoConfig]))
        ->addPass(new ConditionPassTwoPass)
        ->addPass(new FlushDefinitionsPass);

    $kernel->boot();

    $survivingClasses = array_map(
        static fn (BeanDefinition $d): string => $d->class(),
        $bootContext->definitions->all(),
    );

    expect($survivingClasses)->toBe(['App\DefaultCache'])
        ->and($bootContext->container->bound('App\DefaultCache'))->toBeTrue();
});

// --- Regression #4: the "self-seeing" shape, through the REAL composed pipeline ---
//
// Here the AutoConfiguration candidate's OWN descriptor declares `interfaces: [Cache::class]` —
// exactly like a real `final class DefaultCacheAutoConfig implements CachePort {}` starter, or a
// `#[Bean] public function defaultCache(): CachePort {...}` factory method. Under the pre-fix
// batch-filtering ConditionPassTwoPass, BeanDefinitionRegistry::containsType() would see THIS
// candidate's own `interfaces` entry in the batch snapshot and back it off from itself, in BOTH
// scenarios below — including the one with no competing user bean at all, where the candidate is
// the only thing that could ever supply Cache.

it("an AutoConfiguration's #[ConditionalOnMissingBean] applies even when its OWN declared interface supplies the very type it is gated on — the self-seeing regression", function () {
    $bootContext = phaseOrderingBootContext();
    $kernel = new FireflyKernel($bootContext);

    $autoConfig = new BeanDefinition(
        phaseOrderingDescriptor('App\SelfSeeingDefaultCache', interfaces: [Cache::class]),
        conditions: [new ConditionalOnMissingBean(Cache::class)],
        source: DefinitionSource::AutoConfiguration,
    );

    $kernel->addPass(new UserConfigurationsPass([]))
        ->addPass(new ConditionPassOnePass)
        ->addPass(new ConditionPhaseOrderingAutoConfigPass([$autoConfig]))
        ->addPass(new ConditionPassTwoPass)
        ->addPass(new FlushDefinitionsPass);

    $kernel->boot();

    $survivingClasses = array_map(
        static fn (BeanDefinition $d): string => $d->class(),
        $bootContext->definitions->all(),
    );

    expect($survivingClasses)->toBe(['App\SelfSeeingDefaultCache'])
        ->and($bootContext->container->bound('App\SelfSeeingDefaultCache'))->toBeTrue();
});

it("an AutoConfiguration's #[ConditionalOnMissingBean] STILL correctly backs off when its own declared interface AND a user bean both supply the same type", function () {
    $bootContext = phaseOrderingBootContext();
    $kernel = new FireflyKernel($bootContext);

    $userCache = new BeanDefinition(phaseOrderingDescriptor('App\UserCache', interfaces: [Cache::class]));
    $autoConfig = new BeanDefinition(
        phaseOrderingDescriptor('App\SelfSeeingDefaultCache', interfaces: [Cache::class]),
        conditions: [new ConditionalOnMissingBean(Cache::class)],
        source: DefinitionSource::AutoConfiguration,
    );

    $kernel->addPass(new UserConfigurationsPass([$userCache]))
        ->addPass(new ConditionPassOnePass)
        ->addPass(new ConditionPhaseOrderingAutoConfigPass([$autoConfig]))
        ->addPass(new ConditionPassTwoPass)
        ->addPass(new FlushDefinitionsPass);

    $kernel->boot();

    $survivingClasses = array_map(
        static fn (BeanDefinition $d): string => $d->class(),
        $bootContext->definitions->all(),
    );

    expect($survivingClasses)->toBe(['App\UserCache'])
        ->and($bootContext->container->bound('App\UserCache'))->toBeTrue()
        ->and($bootContext->container->bound('App\SelfSeeingDefaultCache'))->toBeFalse();
});
