<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Cache;

/**
 * One compiled #[InvalidatesQuery] (or one firefly.cqrs.query.cache.invalidation entry, whose $eventClass is '' —
 * an event that has no class in this process and is only ever seen as an EDA envelope). See the attribute for
 * what $key and $with mean; neither set means "evict the whole group".
 *
 * $withTypes records, per mapped query property, the type HandlerScanner read off the query (`string`, `?int`, an
 * enum or class name, '' when untyped or a union). An in-process event hands over real typed values, but an EDA
 * envelope from another process carries JSON-decoded ones, so the invalidator rebuilds a with-map key from an
 * envelope only when the payload value has the declared type — otherwise it evicts the group. A rule without types
 * (built by hand, or loaded from a manifest compiled before they existed) always takes that safe path for envelopes.
 *
 * @phpstan-type InvalidationRow array{eventClass: string, eventType: string, queryClass: string, key: string|null, with: array<string, string>, withTypes?: array<string, string>}
 */
final readonly class InvalidationRule
{
    /**
     * @param  array<string, string>  $with  query property => event property
     * @param  array<string, string>  $withTypes  query property => declared type
     */
    public function __construct(
        public string $eventClass,
        public string $eventType,
        public string $queryClass,
        public ?string $key = null,
        public array $with = [],
        public array $withTypes = [],
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
            'withTypes' => $this->withTypes,
        ];
    }

    /**
     * @param  InvalidationRow  $row
     */
    public static function fromArray(array $row): self
    {
        return new self($row['eventClass'], $row['eventType'], $row['queryClass'], $row['key'], $row['with'], $row['withTypes'] ?? []);
    }
}
