<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Workflow\Attributes;

use Attribute;

/**
 * The step starts workflow `workflowId` as a child: the step method's return value (an array) is the child's
 * input. With `waitForCompletion` the parent waits for the child to end (inline for a SYNC child, durably
 * for an ASYNC one) and the step's result is the child's; a failed child fails the step. Otherwise the step
 * completes at once with the child's correlation id. `timeoutMs` fails the step if the child is not done.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class ChildWorkflow
{
    public function __construct(
        public string $workflowId,
        public bool $waitForCompletion = true,
        public int $timeoutMs = 0,
    ) {}
}
