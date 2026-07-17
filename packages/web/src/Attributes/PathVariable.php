<?php

declare(strict_types=1);

namespace Firefly\Web\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
final class PathVariable
{
    /** @param ?string $name the route parameter name (defaults to the method parameter name) */
    public function __construct(public readonly ?string $name = null) {}
}
