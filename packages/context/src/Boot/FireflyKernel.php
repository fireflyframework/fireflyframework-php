<?php

declare(strict_types=1);

namespace Firefly\Context\Boot;

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
     * Runs every phase, in full pipeline order. Shares run()'s per-phase idempotency bookkeeping,
     * so a phase already completed via a prior run() call is not re-run here.
     */
    public function boot(): void
    {
        $this->run(...BootPhase::cases());
    }

    public function context(): BootContext
    {
        return $this->context;
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
