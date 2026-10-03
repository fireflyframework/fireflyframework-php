<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Tests\Support;

/** A clock a test moves by hand: Unix seconds, `$clock(...)` is the registry's `Closure(): float`. */
final class FixedClock
{
    public function __construct(public float $now = 1790000000.0) {}

    public function __invoke(): float
    {
        return $this->now;
    }
}
