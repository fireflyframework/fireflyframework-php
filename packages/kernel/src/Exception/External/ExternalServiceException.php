<?php

declare(strict_types=1);

namespace Firefly\Kernel\Exception\External;

use Firefly\Kernel\Error\ErrorCategory;
use Firefly\Kernel\Error\ErrorSeverity;
use Firefly\Kernel\Exception\FireflyException;
use Throwable;

class ExternalServiceException extends FireflyException
{
    public function __construct(
        string $message = 'External service error',
        string $errorCode = 'EXTERNAL_SERVICE_ERROR',
        int $httpStatus = 502,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $httpStatus, ErrorCategory::External, ErrorSeverity::Error, $previous);
    }
}
