<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Settings;

/**
 * firefly.orchestration.backpressure.* where it means something in synchronous PHP (spec decision D8):
 * `maxInFlight` (0 = unlimited) refuses new starts, `strategy=circuit-breaker` sheds starts after
 * `failureThreshold` FAILED executions, `batchSize` caps one tick or recovery sweep.
 */
final readonly class BackpressureSettings
{
    public function __construct(
        public string $strategy,
        public int $batchSize,
        public int $maxInFlight,
        public int $failureThreshold,
        public float $recoveryTimeout,
        public int $halfOpenMaxCalls,
    ) {}
}
