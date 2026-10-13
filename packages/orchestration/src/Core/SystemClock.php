<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Core;

use DateTimeImmutable;
use DateTimeZone;

/** The wall clock, in UTC. */
final class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
