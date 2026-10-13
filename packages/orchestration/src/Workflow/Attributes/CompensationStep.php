<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Workflow\Attributes;

use Attribute;

/** Undoes step `compensates` of the enclosing workflow when the workflow fails; receives the step result. */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class CompensationStep
{
    public function __construct(public string $compensates) {}
}
