<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Core\Exception;

use Throwable;

/**
 * Compensation stopped before every completed step was undone: an error handler answered FAIL_SAGA, or under
 * CIRCUIT_BREAKER the saga's breaker opened or a `compensationCritical` step failed. The message names the
 * step that stopped it and becomes the execution's recorded reason.
 */
final class CompensationException extends OrchestrationException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 'ORCHESTRATION_COMPENSATION_FAILED', 500, $previous);
    }
}
