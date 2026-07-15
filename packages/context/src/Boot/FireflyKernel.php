<?php

declare(strict_types=1);

namespace Firefly\Context\Boot;

use Closure;
use Firefly\Container\Container as FireflyContainer;
use Firefly\Container\Scanner\ComponentManifest;
use Firefly\Context\Event\DispatcherEventPublisher;
use Firefly\Context\Lifecycle\DisposableBeanRegistry;
use Firefly\Context\Lifecycle\InitDestroyInvoker;
use Firefly\Context\Lifecycle\LifecycleRegistry;
use Illuminate\Container\Container;

/**
 * Runs the ordered boot pipeline. The kernel is the ONLY source of pass ordering — passes are
 * merely *contributed* via addPass(); later milestones (M5–M14) extend the pipeline exclusively
 * through that seam, never by editing this class.
 *
 * The kernel MUST NOT derive ordering from Laravel service-provider registration order: Laravel
 * registers auto-discovered (package) providers BEFORE application providers, which is the
 * INVERSE of what this pipeline needs (an app's own #[Configuration] classes must be free to run
 * relative to auto-configuration passes on the kernel's terms, not on the accident of provider
 * discovery). Deriving order from registration would make boot order silently depend on provider
 * discovery order — precisely the class of bug this kernel exists to eliminate. This is the
 * "harmless simplification" a future maintainer would be tempted to reach for; it is not harmless.
 */
final class FireflyKernel
{
    /** @var list<BootPass> */
    private array $passes = [];

    /**
     * Phases that have already been run, keyed by BootPhase::value. Shared bookkeeping between
     * run() and boot() so that, e.g., run(ConfigAndProfiles) followed by boot() does not re-run
     * ConfigAndProfiles.
     *
     * @var array<int, true>
     */
    private array $completedPhases = [];

    public function __construct(private readonly BootContext $context) {}

    public function addPass(BootPass $pass): self
    {
        $this->passes[] = $pass;

        return $this;
    }

    /**
     * Runs only the named phases, in pipeline order — never the order they were passed in here,
     * and never the order their passes were added via addPass().
     *
     * Idempotent PER PHASE: a phase already marked complete is skipped entirely, making
     * re-running it a no-op. This lets a single phase be booted in isolation (trivially testable)
     * and protects against something triggering boot() twice — e.g. a provider whose boot()
     * callback fires more than once, or a caller invoking run() and then boot() over the same
     * kernel instance.
     */
    public function run(BootPhase ...$phases): void
    {
        /** @var array<int, BootPhase> $requested keyed by BootPhase::value, dedupes repeats */
        $requested = [];
        foreach ($phases as $phase) {
            $requested[$phase->value] = $phase;
        }

        foreach ($this->sortedPasses() as $pass) {
            $phaseValue = $pass->phase()->value;

            if (! isset($requested[$phaseValue]) || isset($this->completedPhases[$phaseValue])) {
                continue;
            }

            $pass->run($this->context);
        }

        foreach ($requested as $phaseValue => $phase) {
            $this->completedPhases[$phaseValue] = true;
        }
    }

    /**
     * Runs every phase, in full pipeline order, then assembles the ApplicationContext. Shares
     * run()'s per-phase idempotency bookkeeping, so a phase already completed via a prior run()
     * call is not re-run here.
     *
     * Assembling the ApplicationContext NEVER requires any particular pass to have been
     * contributed: FireflyContainer/DisposableBeanRegistry/LifecycleRegistry are each bound with a
     * safe, empty default if the pass that would normally bind them (FlushDefinitionsPass,
     * RegisterBeanPostProcessorsPass, InfrastructureStartPass respectively) never ran — e.g. a
     * partial kernel assembled for a focused unit test. When those passes DID run, they already
     * bound the real objects via $container->instance(), so bound() is true and the real state
     * (not the default) is what gets fetched here.
     */
    public function boot(): ApplicationContext
    {
        $this->run(...BootPhase::cases());

        return $this->buildApplicationContext();
    }

    public function context(): BootContext
    {
        return $this->context;
    }

    private function buildApplicationContext(): ApplicationContext
    {
        $container = $this->context->container;

        $facade = $this->boundOrDefault(
            $container,
            FireflyContainer::class,
            static fn (): FireflyContainer => new FireflyContainer($container, new ComponentManifest([])),
        );

        $disposables = $this->boundOrDefault(
            $container,
            DisposableBeanRegistry::class,
            static fn (): DisposableBeanRegistry => new DisposableBeanRegistry(new InitDestroyInvoker($container)),
        );

        $lifecycles = $this->boundOrDefault(
            $container,
            LifecycleRegistry::class,
            static fn (): LifecycleRegistry => new LifecycleRegistry,
        );

        return new ApplicationContext($facade, new DispatcherEventPublisher($container), $disposables, $lifecycles);
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $abstract
     * @param  Closure(): T  $default
     * @return T
     */
    private function boundOrDefault(Container $container, string $abstract, Closure $default): object
    {
        if (! $container->bound($abstract)) {
            $container->instance($abstract, $default());
        }

        /** @var T */
        return $container->make($abstract);
    }

    /**
     * Deterministic total ordering: (phase->value, order(), FQCN).
     *
     * The FQCN ($pass::class) is the FINAL tiebreak, and it exists so the boot plan is totally
     * ordered and reproducible: two passes contributed for the same phase with the same order()
     * must NOT resolve by array insertion order. Without this tiebreak, boot order would silently
     * depend on the order service providers happened to register in — exactly the class of bug
     * this kernel exists to eliminate — and the resolved plan could not be snapshot-tested.
     *
     * @return list<BootPass>
     */
    private function sortedPasses(): array
    {
        $sorted = $this->passes;

        usort($sorted, static function (BootPass $a, BootPass $b): int {
            $phaseComparison = $a->phase()->value <=> $b->phase()->value;
            if ($phaseComparison !== 0) {
                return $phaseComparison;
            }

            $orderComparison = $a->order() <=> $b->order();
            if ($orderComparison !== 0) {
                return $orderComparison;
            }

            return $a::class <=> $b::class;
        });

        return $sorted;
    }
}
