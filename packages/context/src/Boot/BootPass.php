<?php

declare(strict_types=1);

namespace Firefly\Context\Boot;

/**
 * A unit of boot work. Later milestones contribute passes via FireflyKernel::addPass(); the kernel itself
 * never changes. NOTE: pass ordering must NEVER be derived from service-provider registration order —
 * Laravel registers auto-discovered providers BEFORE app providers, the inverse of what the pipeline needs.
 */
interface BootPass
{
    public function phase(): BootPhase;

    /** Tie-break within a phase; lower runs first (the #[Order] convention). */
    public function order(): int;

    public function run(BootContext $context): void;
}
