<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Workflow\Attributes;

use Attribute;

/** Called after a step completes (only the listed `stepIds`, or every step), with the context and/or the step result. */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class OnStepComplete
{
    /**
     * @param  list<string>  $stepIds
     */
    public function __construct(
        public array $stepIds = [],
        public bool $async = false,
        public int $priority = 0,
    ) {}
}
