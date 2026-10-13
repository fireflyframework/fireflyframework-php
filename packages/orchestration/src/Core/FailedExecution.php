<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Core;

use RuntimeException;

/**
 * Carries a FAILED execution's result through firefly/resilience's CircuitBreaker, which counts only thrown
 * failures, and is caught again by ExecutionAdmission so the caller still receives the result object.
 *
 * @internal
 */
final class FailedExecution extends RuntimeException
{
    public function __construct(public readonly object $result)
    {
        parent::__construct('The orchestration execution ended in failure.');
    }
}
