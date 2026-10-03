<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Event;

/**
 * One evaluation through the `firefly` client (spec §4.9), published only with events.evaluations on: the exposure
 * record an experiment's analysis needs. $value snapshots JSON values (arrays/stdClass/scalars/null), preserving
 * stdClass aliases and cycles. Foreign objects, including nested ones, retain their identity and are not isolated.
 * More than 10000 value occurrences, unsupported array-reference graphs or excessive depth omit the event. $variant is null when absent or failed
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
