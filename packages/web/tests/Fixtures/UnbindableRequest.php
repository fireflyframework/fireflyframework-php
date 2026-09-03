<?php

declare(strict_types=1);

namespace Firefly\Web\Tests\Fixtures;

use Countable;

/**
 * A body DTO the resolver CANNOT hydrate: $counter is typed as an interface, so there is no class to
 * construct from the sub-array. The framework owes the client a 400 here, not a TypeError rendered as a 500.
 */
final class UnbindableRequest
{
    public function __construct(
        public readonly string $name,
        public readonly Countable $counter,
    ) {}
}
