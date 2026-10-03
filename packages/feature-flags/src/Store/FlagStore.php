<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Store;

interface FlagStore
{
    /** @return array<array-key, StoredFlag> */
    public function all(): array;

    public function get(string $key): ?StoredFlag;

    public function revision(): int;

    /** @param array<array-key, mixed> $definition */
    public function put(string $key, array $definition, ?string $actor, ?int $expectedVersion = null): FlagChange;

    public function delete(string $key, ?string $actor, ?int $expectedVersion = null): ?FlagChange;

    /** @return list<FlagChange> */
    public function history(string $key, int $limit = 50): array;
}
