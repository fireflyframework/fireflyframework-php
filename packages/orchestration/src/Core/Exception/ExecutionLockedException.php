<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Core\Exception;

/**
 * Another process held the execution's lock for longer than firefly.orchestration.lock.wait. Retryable: the
 * holder is advancing the same execution, and a signal or resume sent again a moment later is safe.
 */
final class ExecutionLockedException extends OrchestrationException
{
    public function __construct(public readonly string $correlationId, float $waitedSeconds)
    {
        parent::__construct(
            "Orchestration execution [{$correlationId}] is being advanced by another process; gave up after {$waitedSeconds}s (firefly.orchestration.lock.wait).",
            'ORCHESTRATION_EXECUTION_LOCKED',
            409,
        );
    }
}
