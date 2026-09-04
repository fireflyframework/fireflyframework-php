<?php

declare(strict_types=1);

namespace Firefly\Web\Tests\Fixtures;

/**
 * A body DTO whose second parameter is enum-typed. `class_exists()` answers TRUE for an enum, so it reaches
 * the same `new $class(...)` a nested DTO does and raises "Cannot instantiate enum" — an \Error, not a
 * TypeError, which is why the hydration guard catches the wider type.
 */
final class PricedRequest
{
    public function __construct(
        public readonly int $cents,
        public readonly Currency $currency,
    ) {}
}
