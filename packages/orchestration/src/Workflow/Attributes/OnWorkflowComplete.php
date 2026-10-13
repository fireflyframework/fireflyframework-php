<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Workflow\Attributes;

use Attribute;

/** Called after the workflow COMPLETED, with the context and/or its final ExecutionState. */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class OnWorkflowComplete
{
    public function __construct(
        public bool $async = false,
        public int $priority = 0,
    ) {}
}
