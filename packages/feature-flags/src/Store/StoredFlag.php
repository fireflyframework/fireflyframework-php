<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Store;

use DateTimeImmutable;

final readonly class StoredFlag
{
    /** @param array<array-key, mixed> $definition */
    public function __construct(
        public string $key,
        public array $definition,
        public int $version,
        public DateTimeImmutable $updatedAt,
        public ?string $updatedBy,
    ) {}
}
