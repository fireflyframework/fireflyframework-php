<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Settings;

/** `firefly.feature-flags.sources.store.*`: the writable layer (`database` or `memory`). */
final readonly class StoreSourceSettings
{
    public function __construct(
        public bool $enabled,
        public string $driver,
        public ?string $connection,
        public float $refreshInterval,
    ) {}
}
