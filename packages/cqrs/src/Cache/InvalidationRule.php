<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Cache;

/**
 * One compiled #[InvalidatesQuery] (or one firefly.cqrs.query.cache.invalidation entry, whose $eventClass is '' —
 * an event that has no class in this process and is only ever seen as an EDA envelope). See the attribute for
 * what $key and $with mean; neither set means "evict the whole group".
 *
 * @phpstan-type InvalidationRow array{eventClass: string, eventType: string, queryClass: string, key: string|null, with: array<string, string>}
 */
final readonly class InvalidationRule
{
    /**
     * @param  array<string, string>  $with
     */
    public function __construct(
        public string $eventClass,
        public string $eventType,
        public string $queryClass,
        public ?string $key = null,
        public array $with = [],
    ) {}

    public function evictsGroup(): bool
    {
        return $this->key === null && $this->with === [];
    }

    /**
     * @return InvalidationRow
     */
    public function toArray(): array
    {
        return [
            'eventClass' => $this->eventClass,
            'eventType' => $this->eventType,
            'queryClass' => $this->queryClass,
            'key' => $this->key,
            'with' => $this->with,
        ];
    }

    /**
     * @param  InvalidationRow  $row
     */
    public static function fromArray(array $row): self
    {
        return new self($row['eventClass'], $row['eventType'], $row['queryClass'], $row['key'], $row['with']);
    }
}
