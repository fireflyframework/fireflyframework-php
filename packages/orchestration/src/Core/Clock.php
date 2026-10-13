<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Core;

use DateTimeImmutable;

/**
 * The engines' only source of "now": timers, deadlines, stale detection and retention all read it, so a test
 * can move time instead of sleeping. Implementations return UTC.
 */
interface Clock
{
    public function now(): DateTimeImmutable;
}
