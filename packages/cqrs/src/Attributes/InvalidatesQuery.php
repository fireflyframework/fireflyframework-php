<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Attributes;

use Attribute;

/**
 * Declared on an EVENT CLASS: when this event happens, cached results of $query are stale. Three shapes:
 *
 *   * neither $key nor $with — evict the query's whole group (every cached result of it);
 *   * $with: ['queryProperty' => 'eventProperty'] — evict the ONE entry whose derived key the query would get with
 *     those property values. The query's every public property must be mapped, with the same types; HandlerScanner
 *     refuses a map that names an unknown property, leaves one out, or targets a Cacheable query or one holding
 *     private or protected state (the types are the author's to keep: the event value is only known at runtime);
 *   * $key: 'statement:{account}' — evict the entry a Cacheable query's own cacheKey() returns, `{name}` resolved
 *     from the event's public properties or from the EDA payload (dotted paths reach into nested arrays).
 *
 * An event published over EDA is matched by $eventType — default: the short class name, which is what
 * DomainEvent::eventType() returns — so another process's envelope evicts this process's cache too. A placeholder
 * the event cannot fill evicts the whole group instead: serving stale data is the failure worth preventing.
 * PyFly's @cache_evict, moved to the event because the event is what knows what it makes stale.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class InvalidatesQuery
{
    /**
     * @param  class-string  $query
     * @param  array<string, string>  $with  query property => event property
     */
    public function __construct(
        public readonly string $query,
        public readonly ?string $key = null,
        public readonly array $with = [],
        public readonly ?string $eventType = null,
    ) {}
}
