<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Core\Argument;

use Attribute;

/** Stores the method's return value as an execution variable under this name. */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class SetVariable
{
    public function __construct(public string $name) {}
}
