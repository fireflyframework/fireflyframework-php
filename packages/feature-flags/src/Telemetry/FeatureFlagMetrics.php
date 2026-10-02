<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Telemetry;

/**
 * feature_flag_evaluations_total{flag, variant, reason} (spec §4.9). firefly/observability supplies the
 * MeterRegistry-backed implementation; NoOpFeatureFlagMetrics stands in without it.
 */
interface FeatureFlagMetrics
{
    public function recordEvaluation(string $flag, string $variant, string $reason): void;
}
