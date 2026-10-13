<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Core\Exception;

use Firefly\Kernel\Exception\Infrastructure\TimeoutException;
use Throwable;

/**
 * A deadline the engine itself enforces passed — the saga-wide default-timeout, a workflow's timeoutMs, a
 * child workflow's timeoutMs. A TimeoutException like the one firefly/resilience's TimeLimiter raises for a
 * step's own timeoutMs, so both mark the step TIMED_OUT through one instanceof check.
 */
class StepTimeoutException extends TimeoutException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 'ORCHESTRATION_STEP_TIMEOUT', $previous);
    }
}
