<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Core\Exception;

use Throwable;

/** A compensation error handler answered FAIL_SAGA: compensation stopped and the saga ends FAILED. */
final class CompensationException extends OrchestrationException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 'ORCHESTRATION_COMPENSATION_FAILED', 500, $previous);
    }
}
