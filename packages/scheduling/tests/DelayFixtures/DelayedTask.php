<?php

declare(strict_types=1);

namespace Firefly\Scheduling\Tests\DelayFixtures;

use Firefly\Scheduling\Attributes\Scheduled;

/**
 * The capstone's one task, and the only #[Scheduled] method in this namespace: a minute cadence held back by
 * a ten-minute initial delay. It lives OUTSIDE CapstoneFixtures on purpose — that namespace's own capstone
 * pins its scan to exactly one descriptor (ReconcileJob::run), so a second task there would inflate it.
 */
final class DelayedTask
{
    #[Scheduled(fixedRate: '1m', initialDelay: '10m')]
    public function run(): void {}
}
