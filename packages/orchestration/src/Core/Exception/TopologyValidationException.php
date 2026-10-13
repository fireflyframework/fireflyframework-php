<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Core\Exception;

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Throwable;

/**
 * A definition that cannot run: a cycle, an unknown dependsOn, a duplicate id, a compensation that names no
 * method, a condition that does not parse. A ConfigurationException, because it is raised at boot (or by
 * firefly:cache) and refuses the application rather than failing its first execution.
 */
final class TopologyValidationException extends ConfigurationException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 'ORCHESTRATION_TOPOLOGY_INVALID', previous: $previous);
    }
}
