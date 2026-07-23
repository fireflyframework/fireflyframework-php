<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Exception;

use Firefly\Kernel\Error\ErrorCategory;
use Firefly\Kernel\Error\ErrorSeverity;
use Firefly\Kernel\Exception\FireflyException;
use Throwable;

/**
 * The wrapper DefaultCommandBus re-throws any handler/stage throwable in (unless it is already a
 * CommandProcessingException, in which case the bus re-throws as-is). CATEGORY-PRESERVING: when the cause is a
 * FireflyException, its httpStatus/category/severity are copied so an expected client/domain fault (validation,
 * not-found) keeps its own kernel category and is NOT masked into a generic 500; a plain Throwable becomes
 * internal/500. Carries the command class + the cause as `previous` (design §2.3 / pyfly command/bus.py:153-164).
 */
final class CommandProcessingException extends CqrsException
{
    public function __construct(string $commandClass, Throwable $cause)
    {
        parent::__construct(
            "Processing command [{$commandClass}] failed: {$cause->getMessage()}",
            'COMMAND_PROCESSING_ERROR',
            $cause instanceof FireflyException ? $cause->httpStatus() : 500,
            $cause instanceof FireflyException ? $cause->category() : ErrorCategory::Internal,
            $cause instanceof FireflyException ? $cause->severity() : ErrorSeverity::Error,
            $cause,
        );
    }
}
