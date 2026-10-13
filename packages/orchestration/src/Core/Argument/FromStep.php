<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Core\Argument;

use Attribute;

/** Injects the result another step already produced (jafly @FromStep): `#[FromStep('reserve')] array $hold`. */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class FromStep
{
    public function __construct(public string $stepId) {}
}
