<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tests\ScannerFixtures\Bad\BadCondition;

use Firefly\Orchestration\Workflow\Attributes\Workflow;
use Firefly\Orchestration\Workflow\Attributes\WorkflowStep;

#[Workflow(id: 'bad-condition')]
final class BadConditionWorkflow
{
    #[WorkflowStep(condition: 'input[')]
    public function step(): void {}
}
