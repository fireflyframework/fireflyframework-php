<?php

declare(strict_types=1);

namespace Firefly\Scheduling\Tests\Support;

/**
 * The scheduling capstone, aimed at the one-task DelayFixtures namespace instead of CapstoneFixtures: same
 * real providers, same real scanner, same real cache-backed lock — only the fixture the manifest is compiled
 * from differs, so `#[Scheduled(initialDelay:)]` is exercised over the shipped boot pipeline without
 * disturbing the task count the sibling capstone pins.
 */
abstract class InitialDelayCapstoneTestCase extends SchedulingCapstoneTestCase
{
    /**
     * @return array<string, string>
     */
    protected function scheduledFixturePsr4(): array
    {
        return ['Firefly\\Scheduling\\Tests\\DelayFixtures\\' => __DIR__.'/../DelayFixtures'];
    }
}
