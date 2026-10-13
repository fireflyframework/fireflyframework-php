<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Core\Exception;

use Throwable;

/**
 * Compensation stopped before every completed step was undone: an error handler answered FAIL_SAGA, or under
 * CIRCUIT_BREAKER the saga's breaker opened or a `compensationCritical` step failed. The message names the
 * step that stopped it and becomes the execution's recorded reason; `stoppedAt` is that step and
 * `uncompensatedSteps` every step with a compensation that was not undone, in compensation order. SagaEngine
 * ends such an execution COMPENSATION_FAILED and dead-letters it.
 */
final class CompensationException extends OrchestrationException
{
    /**
     * @param  list<string>  $uncompensatedSteps
     */
    public function __construct(
        string $message,
        ?Throwable $previous = null,
        public readonly ?string $stoppedAt = null,
        public readonly array $uncompensatedSteps = [],
    ) {
        parent::__construct($message, 'ORCHESTRATION_COMPENSATION_FAILED', 500, $previous);
    }
}
