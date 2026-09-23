<?php

declare(strict_types=1);

namespace Firefly\Scheduling\Tests\DelayFixtures;

use Firefly\Scheduling\Attributes\Scheduled;

/**
 * The control case beside DelayedTask: the same shape, no initial delay, and a five-minute cadence so the
 * capstone can tell the two registered Events apart by their cron expression alone. A gate that held THIS
 * task back would be a gate that had quietly become a global pause button.
 */
final class PromptTask
{
    #[Scheduled(fixedRate: '5m')]
    public function run(): void {}
}
