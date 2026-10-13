<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Persistence;

use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Core\ExecutionStatus;

/** A listing filter: every member optional, results newest-update first, at most `limit` rows. */
final readonly class ExecutionCriteria
{
    public function __construct(
        public ?ExecutionPattern $pattern = null,
        public ?ExecutionStatus $status = null,
        public ?string $name = null,
        public int $limit = 50,
    ) {}

    public function matches(ExecutionState $state): bool
    {
        return ($this->pattern === null || $state->pattern === $this->pattern)
            && ($this->status === null || $state->status === $this->status)
            && ($this->name === null || $state->executionName === $this->name);
    }
}
