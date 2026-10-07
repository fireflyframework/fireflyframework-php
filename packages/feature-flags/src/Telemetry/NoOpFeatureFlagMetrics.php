<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Telemetry;

final class NoOpFeatureFlagMetrics implements FeatureFlagMetrics
{
    public function recordEvaluation(string $flag, string $variant, string $reason): void {}
}
