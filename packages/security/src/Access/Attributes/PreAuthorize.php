<?php

declare(strict_types=1);

namespace Firefly\Security\Access\Attributes;

use Attribute;

/** Guards a method (or every method of a class) with a SpEL-subset boolean expression (evaluated no-`eval`). */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final readonly class PreAuthorize
{
    public function __construct(public string $expression) {}
}
