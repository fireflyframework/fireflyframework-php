<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Workflow\Attributes;

use Attribute;

/**
 * A read-only question about a running or finished execution (jafly @WorkflowQuery), answered by
 * WorkflowEngine::query($id, $name, $args): the method receives the execution's ExecutionContext (rebuilt from
 * its checkpoint) when its first parameter is typed ExecutionContext, then the arguments.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class WorkflowQuery
{
    public function __construct(public string $name) {}
}
