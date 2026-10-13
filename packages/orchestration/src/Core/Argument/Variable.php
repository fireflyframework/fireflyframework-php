<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Core\Argument;

use Attribute;

/** Injects one execution variable; a delivered workflow signal's payload is the variable `signals.<name>`. */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Variable
{
    public function __construct(public string $name) {}
}
