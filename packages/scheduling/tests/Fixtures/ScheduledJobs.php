<?php

declare(strict_types=1);

namespace Firefly\Scheduling\Tests\Fixtures;

use Firefly\Scheduling\Attributes\Scheduled;

final class ScheduledJobs
{
    public int $runs = 0;

    #[Scheduled(cron: '* * * * *', lock: true)]
    public function reconcile(): void
    {
        $this->runs++;
    }

    // No #[Scheduled] — proves the scanner only emits descriptors for annotated methods.
    public function helper(): void {}
}
