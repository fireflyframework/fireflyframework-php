<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Core\Exception;

/** No execution with this correlation id is persisted (or it has been cleaned up). */
final class ExecutionNotFoundException extends OrchestrationException
{
    public function __construct(public readonly string $correlationId)
    {
        parent::__construct("No orchestration execution [{$correlationId}] is persisted.", 'ORCHESTRATION_EXECUTION_NOT_FOUND', 404);
    }
}
