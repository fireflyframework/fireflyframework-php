<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Config\Profile\Profiles;
use Firefly\Container\Descriptor\ComponentDescriptor;
use Firefly\Container\Scope;
use Firefly\Context\Boot\BootContext;
use Firefly\Context\Condition\ConditionEvaluationReport;
use Firefly\Context\Condition\ConditionEvaluator;
use Firefly\Context\Definition\BeanDefinition;
use Firefly\Context\Definition\BeanDefinitionRegistry;
use Firefly\Context\Definition\StaleDefinitionReport;
use Firefly\Context\Pass\EagerSingletonsPass;
use Firefly\Context\Pass\FlushDefinitionsPass;
use Firefly\Context\Pass\InfrastructureStartPass;
use Firefly\Context\Pass\RegisterBeanPostProcessorsPass;
use Firefly\Context\Processor\BeanPostProcessor;
use Firefly\Context\Scanner\ContextManifest;
use Firefly\Kernel\Lifecycle;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\BindingResolutionException;

/**
 * Deleting one #[Component] that implemented an interface bricked an application, and EagerSingletonsPass's
 * v26.09.1 guard did not save it.
 *
 * The guard skipped the deleted descriptor correctly. The throw came from somewhere else: the descriptor was
 * still in the manifest ContainerRegistrar registered, so wireInterfaces() bound the INTERFACE to the missing
 * class and tagged it as an implementation, and `Target class [...] does not exist` was raised while resolving
 * a bean that still existed and injected that contract. Nothing in the error named the file that had been
 * deleted. Three more passes read a class straight off the same manifest with nothing between them and make():
 * RegisterBeanPostProcessorsPass at phase 700 — BEFORE eager singletons — InfrastructureStartPass, and
 * RegisterEventListenersPass, whose listener closure throws on the first dispatch instead of at boot.
 *
 * Reproduced end to end on 2026-09-24 against a real application: `php artisan firefly:cache` exited 1 without
 * writing anything, `composer dump-autoload` could not recover it either (package:discover boots the
 * application too), and the only way out was `rm bootstrap/cache/firefly/*.php`, then `composer
 * dump-autoload`, then `firefly:cache` — in that order.
 *
 * Each case below is one of those paths, run the way boot runs it: the definitions are filtered at the
 * registry door (BeanDefinitionRegistry::add), FlushDefinitionsPass hands the filtered manifest to
 * ContainerRegistrar, and the passes go to work on what comes out. The last case is the control that matters
 * most — under any command that is not repairing the cache, nothing is filtered and the boot still fails
 * exactly as loudly as it did before.
 */
interface StaleWiringContract {}

final class StaleWiringSurvivor implements StaleWiringContract {}

final class StaleWiringConsumer
{
    public function __construct(public readonly StaleWiringContract $contract) {}
}

final class StaleWiringProcessor implements BeanPostProcessor
{
    public function beforeInitialization(object $bean, string $declaredClass): object
    {
        return $bean;
    }

    public function afterInitialization(object $bean, string $declaredClass): object
    {
        return $bean;
    }
}

final class StaleWiringLifecycle implements Lifecycle
{
    public static bool $started = false;

    public function start(): void
    {
        self::$started = true;
    }

    public function stop(): void
    {
        self::$started = false;
    }
}

/** The class a developer deleted: named by the compiled manifest, findable by no autoloader. */
const STALE_WIRING_GHOST = 'App\\Deleted\\ControlPlaneJwksProvider';

const STALE_WIRING_GHOST_PROCESSOR = 'App\\Deleted\\GoneBeanPostProcessor';

const STALE_WIRING_GHOST_LIFECYCLE = 'App\\Deleted\\GoneLifecycle';

/**
 * @param  list<class-string>  $interfaces
 */
function staleWiringDefinition(string $class, array $interfaces = [], bool $primary = false, int $order = 0): BeanDefinition
{
    return new BeanDefinition(new ComponentDescriptor(
        class: $class,
        stereotype: 'component',
        name: null,
        scope: Scope::Singleton,
        primary: $primary,
        order: $order,
        qualifier: null,
        interfaces: $interfaces,
        beans: [],
    ));
}

/**
 * A BootContext over a registry that either filters (a repair boot) or does not (everything else).
 *
 * @param  list<BeanDefinition>  $definitions
 */
function staleWiringContext(array $definitions, bool $repairing, ?StaleDefinitionReport $report = null): BootContext
{
    $registry = new BeanDefinitionRegistry($report ?? new StaleDefinitionReport, dropMissingClasses: $repairing);
    foreach ($definitions as $definition) {
        $registry->add($definition);
    }

    $config = new Config(new Repository([]));
    $profiles = new Profiles(['default']);

    return new BootContext(
        container: new Container,
        definitions: $registry,
        config: $config,
        profiles: $profiles,
        conditions: new ConditionEvaluator($config, $profiles),
        report: new ConditionEvaluationReport,
        contextManifest: new ContextManifest([]),
    );
}

it('binds the contract to the implementation that survived, not to the one that was deleted', function () {
    $report = new StaleDefinitionReport;

    $context = staleWiringContext([
        // #[Primary], which is precisely why the stale entry used to win the interface binding.
        staleWiringDefinition(STALE_WIRING_GHOST, [StaleWiringContract::class], primary: true),
        staleWiringDefinition(StaleWiringSurvivor::class, [StaleWiringContract::class]),
        staleWiringDefinition(StaleWiringConsumer::class),
    ], repairing: true, report: $report);

    (new FlushDefinitionsPass)->run($context);

    // The whole failure, in one line: this used to throw
    // BindingResolutionException("Target class [App\Deleted\ControlPlaneJwksProvider] does not exist.")
    // while resolving a consumer that was never deleted and never changed.
    (new EagerSingletonsPass)->run($context);

    /** @var StaleWiringConsumer $consumer */
    $consumer = $context->container->make(StaleWiringConsumer::class);

    expect($consumer->contract)->toBeInstanceOf(StaleWiringSurvivor::class)
        ->and($context->container->make(StaleWiringContract::class))->toBeInstanceOf(StaleWiringSurvivor::class)
        ->and($report->classes())->toBe([STALE_WIRING_GHOST]);
});

// getAll()/tagged() resolve every tagged implementation, so a ghost left in the tag list throws there too —
// on a contract whose other implementations are all perfectly healthy.
it('leaves the deleted implementation out of the contract tag', function () {
    $context = staleWiringContext([
        staleWiringDefinition(STALE_WIRING_GHOST, [StaleWiringContract::class]),
        staleWiringDefinition(StaleWiringSurvivor::class, [StaleWiringContract::class]),
    ], repairing: true);

    (new FlushDefinitionsPass)->run($context);

    $tagged = iterator_to_array($context->container->tagged('firefly.contract.'.StaleWiringContract::class));

    expect($tagged)->toHaveCount(1)
        ->and($tagged[0])->toBeInstanceOf(StaleWiringSurvivor::class);
});

// Phase 700, BEFORE eager singletons: a deleted BeanPostProcessor took the boot down earlier than the guard
// EagerSingletonsPass carries could ever run.
it('registers the surviving bean post-processors past a deleted one', function () {
    $context = staleWiringContext([
        staleWiringDefinition(STALE_WIRING_GHOST_PROCESSOR, [BeanPostProcessor::class]),
        staleWiringDefinition(StaleWiringProcessor::class, [BeanPostProcessor::class]),
        staleWiringDefinition(StaleWiringSurvivor::class),
    ], repairing: true);

    (new FlushDefinitionsPass)->run($context);
    (new RegisterBeanPostProcessorsPass)->run($context);

    expect($context->container->make(StaleWiringSurvivor::class))->toBeInstanceOf(StaleWiringSurvivor::class);
});

it('starts the surviving lifecycles past a deleted one', function () {
    StaleWiringLifecycle::$started = false;

    $context = staleWiringContext([
        staleWiringDefinition(STALE_WIRING_GHOST_LIFECYCLE, [Lifecycle::class]),
        staleWiringDefinition(StaleWiringLifecycle::class, [Lifecycle::class]),
    ], repairing: true);

    (new FlushDefinitionsPass)->run($context);
    (new InfrastructureStartPass)->run($context);

    expect(StaleWiringLifecycle::$started)->toBeTrue();
});

/*
 | THE CONTROL, and the reason the filter is scoped to a repair boot rather than applied to every one.
 |
 | Under any other command the manifest is trusted exactly as it was before this change, and a class that
 | has gone missing still takes the boot down with the container's own error. That is the right answer
 | there: a missing class in a served process is a broken deployment — a truncated artifact, a classmap
 | built from a different tree — and dropping the definition would let the application serve traffic with
 | this contract quietly rebound to whichever implementation happened to survive. A wrong answer nobody is
 | told about is far worse than the boot failure this change removes.
 */
it('still fails loudly under any command that is not repairing the cache', function () {
    $context = staleWiringContext([
        staleWiringDefinition(STALE_WIRING_GHOST, [StaleWiringContract::class], primary: true),
        staleWiringDefinition(StaleWiringSurvivor::class, [StaleWiringContract::class]),
        staleWiringDefinition(StaleWiringConsumer::class),
    ], repairing: false);

    (new FlushDefinitionsPass)->run($context);

    expect(fn () => (new EagerSingletonsPass)->run($context))
        ->toThrow(BindingResolutionException::class, 'Target class ['.STALE_WIRING_GHOST.'] does not exist.');
});

// Only a MISSING class is ever tolerated, in a repair boot as much as anywhere else. A class that exists and
// genuinely cannot be built is a defect in code that is still there, and failing fast at boot is right for it.
it('still fails fast in a repair boot when a class that DOES exist cannot be constructed', function () {
    $context = staleWiringContext([
        staleWiringDefinition(StaleWiringConsumer::class),
    ], repairing: true);

    (new FlushDefinitionsPass)->run($context);

    expect(fn () => (new EagerSingletonsPass)->run($context))
        ->toThrow(BindingResolutionException::class);
});
