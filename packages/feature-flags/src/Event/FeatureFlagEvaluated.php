<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Event;

/**
 * One evaluation through the `firefly` client (spec §4.9), published only with events.evaluations on: the exposure
 * record an experiment's analysis needs. $value is the value the caller was served — a copy of its own, which the
 * caller cannot change afterwards — and $variant is null when there is none or when the evaluation failed
 * ($reason `ERROR`, $errorCode the OpenFeature error code).
 */
final readonly class FeatureFlagEvaluated
{
    public function __construct(
        public string $key,
        public mixed $value,
        public ?string $variant,
        public string $reason,
        public ?string $errorCode,
        public ?string $targetingKey,
    ) {}
}
