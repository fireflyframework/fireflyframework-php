<?php

declare(strict_types=1);

namespace Firefly\Messaging\Container;

/** initial × multiplier^(failures − 1), capped at maxDelay — the container default (PyFly: 1s, ×2, 30s). */
final readonly class ExponentialBackOff implements BackOff
{
    public function __construct(
        public float $initial = 1.0,
        public float $multiplier = 2.0,
        public float $maxDelay = 30.0,
    ) {}

    public function delayAfter(int $failures): float
    {
        return max(0.0, min($this->maxDelay, $this->initial * ($this->multiplier ** max(0, $failures - 1))));
    }
}
