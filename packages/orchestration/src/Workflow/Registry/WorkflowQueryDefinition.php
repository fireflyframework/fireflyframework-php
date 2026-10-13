<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Workflow\Registry;

use Firefly\Orchestration\Core\Invocation;

/** A compiled #[WorkflowQuery] (or a builder query closure, which always receives the context first). */
final readonly class WorkflowQueryDefinition
{
    public function __construct(
        public string $name,
        public Invocation $invocation,
        public bool $contextFirst = true,
    ) {}
}
