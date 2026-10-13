<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Cache;

/**
 * One compiled #[CachedQuery]: the query class, its TTL in seconds (null = the bus default) and its invalidation
 * group. Scalar-only so the handler manifest var_exports as a plain array literal.
 *
 * @phpstan-type CachedQueryRow array{queryClass: string, ttl: int|null, group: string}
 */
final readonly class CachedQueryPolicy
{
    public function __construct(
        public string $queryClass,
        public ?int $ttl,
        public string $group,
    ) {}

    /**
     * @return CachedQueryRow
     */
    public function toArray(): array
    {
        return ['queryClass' => $this->queryClass, 'ttl' => $this->ttl, 'group' => $this->group];
    }

    /**
     * @param  CachedQueryRow  $row
     */
    public static function fromArray(array $row): self
    {
        return new self($row['queryClass'], $row['ttl'], $row['group']);
    }
}
