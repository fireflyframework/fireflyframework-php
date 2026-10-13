<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Exception;

use Firefly\Kernel\Error\ErrorSeverity;
use Firefly\Kernel\Exception\Infrastructure\InfrastructureException;
use Throwable;

/** The base of every event-sourcing failure that is not a concurrency conflict or a missing aggregate. */
class EventSourcingException extends InfrastructureException
{
    public function __construct(string $message, string $errorCode = 'EVENT_SOURCING_ERROR', int $httpStatus = 500, ?Throwable $previous = null)
    {
        parent::__construct($message, $errorCode, $httpStatus, ErrorSeverity::Error, $previous);
    }
}
