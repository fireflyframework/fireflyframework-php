<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Event;

final readonly class FeatureFlagUpdated
{
    /**
     * @param  array<array-key, mixed>|null  $previous
     * @param  array<array-key, mixed>|null  $current
     */
    public function __construct(
        public string $key,
        public string $action,
        public ?string $actor,
        public ?array $previous,
        public ?array $current,
    ) {}
}
