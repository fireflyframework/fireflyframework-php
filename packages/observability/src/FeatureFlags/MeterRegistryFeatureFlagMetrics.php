<?php

declare(strict_types=1);

namespace Firefly\Observability\FeatureFlags;

use Firefly\FeatureFlags\Telemetry\FeatureFlagMetrics;
use Firefly\Observability\Metrics\MetricsRecorder;

/**
 * feature_flag_evaluations_total{flag, variant, reason} on the MeterRegistry — the MeterRegistryCqrsMetrics
 * seam. Tags are bounded: flag keys and variants come from the flag definitions, reasons from a fixed set.
 */
final class MeterRegistryFeatureFlagMetrics implements FeatureFlagMetrics
{
    public function __construct(private readonly MetricsRecorder $recorder) {}

    public function recordEvaluation(string $flag, string $variant, string $reason): void
    {
        $this->recorder->increment('feature_flag_evaluations_total', ['flag' => $flag, 'variant' => $variant, 'reason' => $reason]);
    }
}
