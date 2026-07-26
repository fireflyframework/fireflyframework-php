<?php

declare(strict_types=1);

namespace Firefly\Observability\Metrics;

/**
 * The narrow write port instrumentation depends on (CqrsMetrics, the HTTP filter, process metrics) so it never
 * needs the full registry. NoOpMetricsRecorder is the safe fallback when metrics are disabled.
 */
interface MetricsRecorder
{
    /** @param array<string, string> $tags */
    public function increment(string $name, array $tags = [], float $amount = 1.0): void;

    /** @param array<string, string> $tags */
    public function record(string $name, array $tags = [], float $seconds = 0.0): void;

    /** @param array<string, string> $tags */
    public function setGauge(string $name, array $tags, float $value): void;
}
