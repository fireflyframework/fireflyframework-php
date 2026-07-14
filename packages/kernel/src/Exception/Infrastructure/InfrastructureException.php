<?php

declare(strict_types=1);

namespace Firefly\Kernel\Exception\Infrastructure;

use Firefly\Kernel\Error\ErrorCategory;
use Firefly\Kernel\Error\ErrorSeverity;
use Firefly\Kernel\Exception\FireflyException;
use Throwable;

class InfrastructureException extends FireflyException
{
    public function __construct(
        string $message,
        string $errorCode = 'INFRASTRUCTURE_ERROR',
        int $httpStatus = 500,
        ErrorSeverity $severity = ErrorSeverity::Error,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $httpStatus, ErrorCategory::Infrastructure, $severity, $previous);
    }
}
