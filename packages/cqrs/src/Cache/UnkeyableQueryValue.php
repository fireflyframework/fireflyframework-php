<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Cache;

use RuntimeException;

/**
 * Internal to QueryCacheKey: a value that cannot be part of a stable cache key (a closure, a resource, a NaN, a
 * structure deeper than QueryCacheKey::MAX_DEPTH). Never escapes QueryCacheKey, which answers null instead —
 * "do not cache this call" — so an exotic query degrades to running its handler, never to an error.
 *
 * @internal
 */
final class UnkeyableQueryValue extends RuntimeException {}
