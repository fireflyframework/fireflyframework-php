<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Settings;

/** firefly.orchestration.persistence.* — durations in seconds; `keyTtl` (cache provider) null means forever. */
final readonly class PersistenceSettings
{
    public function __construct(
        public string $provider,
        public ?string $connection,
        public ?string $cacheStore,
        public string $keyPrefix,
        public ?int $keyTtl,
        public float $retentionPeriod,
        public float $cleanupInterval,
    ) {}
}
