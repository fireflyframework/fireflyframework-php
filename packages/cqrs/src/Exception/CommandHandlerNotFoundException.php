<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Exception;

use Firefly\Kernel\Error\ErrorCategory;
use Firefly\Kernel\Error\ErrorSeverity;
use Throwable;

/**
 * Thrown by HandlerRegistry::findCommandHandler when no handler is registered for a command class — a wiring/config
 * mistake (an unmapped command sent through the bus), so a framework-category 500, not a client fault.
 */
final class CommandHandlerNotFoundException extends CqrsException
{
    public function __construct(string $commandClass, ?Throwable $previous = null)
    {
        parent::__construct(
            "No command handler registered for [{$commandClass}].",
            'COMMAND_HANDLER_NOT_FOUND',
            500,
            ErrorCategory::Framework,
            ErrorSeverity::Error,
            $previous,
        );
    }
}
