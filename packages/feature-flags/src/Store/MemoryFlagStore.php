<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Store;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Firefly\FeatureFlags\Definition\Json;

final class MemoryFlagStore implements FlagStore
{
    /** @var array<string, StoredFlag> */
    private array $flags = [];

    /** @var list<FlagChange> */
    private array $changes = [];

    private readonly Closure $clock;

    /** @param (Closure(): DateTimeImmutable)|null $clock */
    public function __construct(?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function all(): array
    {
        $flags = [];
        foreach ($this->flags as $key => $flag) {
            $flags[$key] = $this->copyFlag($flag);
        }
        ksort($flags, SORT_STRING);

        return $flags;
    }

    public function get(string $key): ?StoredFlag
    {
        $flag = $this->flags[$key] ?? null;

        return $flag === null ? null : $this->copyFlag($flag);
    }

    public function revision(): int
    {
        return count($this->changes);
    }

    public function put(string $key, array $definition, ?string $actor, ?int $expectedVersion = null): FlagChange
    {
        $current = $this->flags[$key] ?? null;
        FlagStoreConflict::check($key, $expectedVersion, $current?->version);
        $now = ($this->clock)();
        $owned = self::copyDefinition($definition);
        $next = new StoredFlag($key, $owned, ($current === null ? 0 : $current->version) + 1, $now, $actor);
        $change = new FlagChange(count($this->changes) + 1, $key, FlagChange::PUT, $owned, $current?->definition, $actor, $now);
        $this->flags[$key] = $next;
        $this->changes[] = $change;

        return $this->copyChange($change);
    }

    public function delete(string $key, ?string $actor, ?int $expectedVersion = null): ?FlagChange
    {
        $current = $this->flags[$key] ?? null;
        FlagStoreConflict::check($key, $expectedVersion, $current?->version);
        if ($current === null) {
            return null;
        }
        $now = ($this->clock)();
        $change = new FlagChange(count($this->changes) + 1, $key, FlagChange::DELETE, null, $current->definition, $actor, $now);
        unset($this->flags[$key]);
        $this->changes[] = $change;

        return $this->copyChange($change);
    }

    public function history(string $key, int $limit = 50): array
    {
        $rows = array_values(array_filter($this->changes, static fn (FlagChange $change): bool => $change->key === $key));

        return array_map($this->copyChange(...), array_slice(array_reverse($rows), 0, max(0, $limit)));
    }

    /** @param array<array-key, mixed> $definition
     * @return array<array-key, mixed>
     */
    private static function copyDefinition(array $definition): array
    {
        return Json::members(Json::decode(Json::canonical(Json::object($definition))));
    }

    private function copyFlag(StoredFlag $flag): StoredFlag
    {
        return new StoredFlag($flag->key, self::copyDefinition($flag->definition), $flag->version, $flag->updatedAt, $flag->updatedBy);
    }

    private function copyChange(FlagChange $change): FlagChange
    {
        return new FlagChange(
            $change->id,
            $change->key,
            $change->action,
            $change->definition === null ? null : self::copyDefinition($change->definition),
            $change->previous === null ? null : self::copyDefinition($change->previous),
            $change->actor,
            $change->changedAt,
        );
    }
}
