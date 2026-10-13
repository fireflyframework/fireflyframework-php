<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Core\Exception;

/** No dead-letter entry with this id exists. */
final class DeadLetterNotFoundException extends OrchestrationException
{
    public function __construct(public readonly string $id)
    {
        parent::__construct("No orchestration dead-letter entry [{$id}] exists.", 'ORCHESTRATION_DEAD_LETTER_NOT_FOUND', 404);
    }
}
