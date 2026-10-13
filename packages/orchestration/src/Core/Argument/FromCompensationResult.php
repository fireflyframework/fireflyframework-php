<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Core\Argument;

use Attribute;

/** Injects what a step's compensation returned. */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class FromCompensationResult
{
    public function __construct(public string $stepId) {}
}
