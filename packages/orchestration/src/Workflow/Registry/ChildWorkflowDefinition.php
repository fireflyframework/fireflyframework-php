<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Workflow\Registry;

/** A compiled #[ChildWorkflow]. */
final readonly class ChildWorkflowDefinition
{
    public function __construct(
        public string $workflowId,
        public bool $waitForCompletion = true,
        public int $timeoutMs = 0,
    ) {}
}
