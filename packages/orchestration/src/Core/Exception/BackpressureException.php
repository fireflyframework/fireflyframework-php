<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Core\Exception;

/** A new execution was refused because firefly.orchestration.backpressure.max-in-flight is reached. */
final class BackpressureException extends OrchestrationException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 'ORCHESTRATION_BACKPRESSURE', 503);
    }
}
