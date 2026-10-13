<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use Firefly\Orchestration\Core\Clock;

/** A clock tests move by hand, so timers, deadlines and stale thresholds are asserted without sleeping. */
final class MutableClock implements Clock
{
    private DateTimeImmutable $now;

    public function __construct(string $at = '2026-10-12T10:00:00+00:00')
    {
        $this->set($at);
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function set(string $at): void
    {
        $this->now = (new DateTimeImmutable($at))->setTimezone(new DateTimeZone('UTC'));
    }

    public function advance(float $seconds): void
    {
        $this->now = $this->now->modify(sprintf('%+d microseconds', (int) round($seconds * 1_000_000)));
    }
}
