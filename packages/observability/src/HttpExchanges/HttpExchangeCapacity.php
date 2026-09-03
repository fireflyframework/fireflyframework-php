<?php

declare(strict_types=1);

namespace Firefly\Observability\HttpExchanges;

/**
 * The one place the ring's size bounds live, shared by both recorders so a configured capacity cannot mean two
 * different things depending on which store is wired.
 *
 * BOTH ends of the clamp are load-bearing, and neither is a style preference:
 *
 *  - The floor of 1 exists because `firefly.observability.httpexchanges.capacity = 0` is a plausible way for
 *    someone to try to turn recording off, and a capacity of 0 would make InMemoryHttpExchangeRecorder's eviction
 *    loop discard the exchange it was just handed (harmless) while CacheHttpExchangeRecorder computed
 *    `$seq % 0` — a DivisionByZeroError thrown from inside a web filter, i.e. a config typo that 500s every
 *    request in the application. The way to turn recording off is
 *    `firefly.observability.httpexchanges.enabled = false`, which un-registers the filter entirely.
 *  - The ceiling of 10,000 exists because the cache-backed recorder reads EVERY slot to answer one endpoint call.
 *    Capacity is the number of cache keys fetched per dashboard render, so an operator who types 1000000 hoping
 *    for more history instead builds an endpoint that issues a million-key multi-get and times out. 10,000 rows
 *    is already far past what any dashboard renders.
 */
final class HttpExchangeCapacity
{
    public const DEFAULT = 100;

    public const MIN = 1;

    public const MAX = 10_000;

    public static function clamp(int $capacity): int
    {
        return max(self::MIN, min(self::MAX, $capacity));
    }
}
