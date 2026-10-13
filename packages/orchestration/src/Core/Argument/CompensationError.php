<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Core\Argument;

use Attribute;

/** Injects the Throwable a step's compensation raised (a RuntimeException carrying its message after a resume). */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class CompensationError
{
    public function __construct(public string $stepId) {}
}
