<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Core\Exception;

/** No saga, TCC or workflow is registered under the requested name. */
final class DefinitionNotFoundException extends OrchestrationException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 'ORCHESTRATION_DEFINITION_NOT_FOUND', 404);
    }
}
