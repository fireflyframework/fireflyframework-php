<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Workflow\Attributes;

use Attribute;

/**
 * One workflow step (jafly @WorkflowStep); `id` defaults to the method name. `condition` is an
 * ExpressionLanguage expression over `input`, `results`, `variables`, `headers`; false SKIPS the step.
 * `outputEventType` publishes the step result through firefly/eda under that event type. A `compensatable`
 * step is undone when the workflow fails, by `compensationMethod` on the same class or a #[CompensationStep].
 * `async` is accepted for parity and has no effect (spec decision D2).
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class WorkflowStep
{
    /**
     * @param  list<string>  $dependsOn
     */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public string $description = '',
        public array $dependsOn = [],
        public string $outputEventType = '',
        public int $timeoutMs = 0,
        public int $maxRetries = -1,
        public int $retryDelayMs = -1,
        public string $condition = '',
        public bool $async = false,
        public bool $compensatable = false,
        public string $compensationMethod = '',
    ) {}
}
