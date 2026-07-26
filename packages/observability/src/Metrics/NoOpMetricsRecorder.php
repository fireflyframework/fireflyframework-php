<?php

declare(strict_types=1);

namespace Firefly\Observability\Metrics;

/** The default recorder when metrics are disabled — records nothing, so instrumentation stays a safe no-op. */
final class NoOpMetricsRecorder implements MetricsRecorder
{
    public function increment(string $name, array $tags = [], float $amount = 1.0): void {}

    public function record(string $name, array $tags = [], float $seconds = 0.0): void {}

    public function setGauge(string $name, array $tags, float $value): void {}
}
