<?php

declare(strict_types=1);

namespace Firefly\Scheduling\Tests\CapstoneFixtures;

/** A shared singleton the scheduled job increments, so the capstone can observe whether a tick ran. */
final class SpyCounter
{
    public int $count = 0;

    public function increment(): void
    {
        $this->count++;
    }
}
