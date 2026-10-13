<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Handler;

use Firefly\Cqrs\Cache\CachedQueryPolicy;
use Firefly\Cqrs\Cache\InvalidationRule;
use Firefly\Kernel\Exception\Framework\ConfigurationException;

/**
 * The compiled, reflection-free handler source the CqrsHandlerWiringPass reads at boot (handler rows) and the
 * commandEventPublisher bean reads for routing (the #[PublishDomainEvent] destinations map). Loaded via require+map;
 * both halves are pure arrays so the whole manifest is a plain PHP array literal with two top-level keys
 * (`handlers`, `destinations`), plus the two query-cache sections (`cachedQueries` — #[CachedQuery] policies keyed by
 * query class — and `invalidations` — #[InvalidatesQuery] rules in scan order), both optional on load so a manifest
 * compiled before they existed still loads. Mirrors the ScheduledManifest / EventListenerManifest idiom.
 *
 * @phpstan-import-type HandlerRow from HandlerDescriptor
 * @phpstan-import-type CachedQueryRow from CachedQueryPolicy
 * @phpstan-import-type InvalidationRow from InvalidationRule
 */
final class HandlerManifest
{
    /**
     * @param  list<HandlerDescriptor>  $handlers
     * @param  array<string,string>  $destinations
     * @param  array<string, CachedQueryPolicy>  $cachedQueries  query class => policy (#[CachedQuery])
     * @param  list<InvalidationRule>  $invalidations  #[InvalidatesQuery] rules, in scan order
     */
    public function __construct(
        private readonly array $handlers,
        private readonly array $destinations,
        private readonly array $cachedQueries = [],
        private readonly array $invalidations = [],
    ) {}

    /**
     * @param  array{handlers?: array<int, HandlerRow>, destinations?: array<string,string>, cachedQueries?: array<string, CachedQueryRow>, invalidations?: array<int, InvalidationRow>}  $data
     */
    public static function fromArray(array $data): self
    {
        $handlers = array_map(
            static fn (array $row): HandlerDescriptor => HandlerDescriptor::fromArray($row),
            array_values($data['handlers'] ?? []),
        );

        $cachedQueries = [];
        foreach ($data['cachedQueries'] ?? [] as $queryClass => $row) {
            $cachedQueries[(string) $queryClass] = CachedQueryPolicy::fromArray($row);
        }

        $invalidations = array_map(
            static fn (array $row): InvalidationRule => InvalidationRule::fromArray($row),
            array_values($data['invalidations'] ?? []),
        );

        // A handlers.php compiled before the cache sections existed has neither key: both default to empty.
        return new self($handlers, $data['destinations'] ?? [], $cachedQueries, $invalidations);
    }

    public static function load(string $path): self
    {
        if (! is_file($path)) {
            throw new ConfigurationException("Handler manifest not found at {$path}. Run the handler scan first.");
        }

        /** @var mixed $data */
        $data = require $path;
        if (! is_array($data)) {
            throw new ConfigurationException("Handler manifest at {$path} did not return an array.");
        }

        /** @var array{handlers?: array<int, HandlerRow>, destinations?: array<string,string>, cachedQueries?: array<string, CachedQueryRow>, invalidations?: array<int, InvalidationRow>} $data */
        return self::fromArray($data);
    }

    /**
     * @return list<HandlerDescriptor>
     */
    public function handlers(): array
    {
        return $this->handlers;
    }

    /**
     * @return array<string,string>
     */
    public function destinations(): array
    {
        return $this->destinations;
    }

    /**
     * @return array<string, CachedQueryPolicy>
     */
    public function cachedQueries(): array
    {
        return $this->cachedQueries;
    }

    /**
     * @return list<InvalidationRule>
     */
    public function invalidations(): array
    {
        return $this->invalidations;
    }

    public function cachePolicyFor(string $queryClass): ?CachedQueryPolicy
    {
        return $this->cachedQueries[$queryClass] ?? null;
    }
}
