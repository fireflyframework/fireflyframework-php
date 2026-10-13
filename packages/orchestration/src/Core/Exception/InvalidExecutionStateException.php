<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Core\Exception;

/** The operation does not apply to the execution's current status (resume a running workflow, signal a finished one). */
final class InvalidExecutionStateException extends OrchestrationException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 'ORCHESTRATION_INVALID_STATE', 409);
    }
}
