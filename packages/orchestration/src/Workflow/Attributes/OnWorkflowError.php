<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Workflow\Attributes;

use Attribute;

/**
 * Called after the workflow failed and was compensated; `stepIds` restricts it to failures of those steps,
 * `suppressError` ends the workflow COMPLETED instead of FAILED.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class OnWorkflowError
{
    /**
     * @param  list<string>  $errorTypes  exception class names; empty matches every error
     * @param  list<string>  $stepIds
     */
    public function __construct(
        public array $errorTypes = [],
        public array $stepIds = [],
        public bool $async = false,
        public int $priority = 0,
        public bool $suppressError = false,
    ) {}
}
