<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Core\Exception;

use Firefly\Orchestration\Core\ExecutionPattern;

/** The pattern's `enabled` key is false: a clear 503 rather than a missing bean (spec decision D18). */
final class PatternDisabledException extends OrchestrationException
{
    public function __construct(ExecutionPattern $pattern, string $key)
    {
        parent::__construct("{$pattern->value} executions are disabled ({$key} is false).", 'ORCHESTRATION_DISABLED', 503);
    }
}
