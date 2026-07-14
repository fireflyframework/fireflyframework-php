<?php

declare(strict_types=1);

namespace Firefly\Kernel\Exception\Business;

use Firefly\Kernel\Error\ErrorCategory;
use Firefly\Kernel\Error\ErrorSeverity;
use Firefly\Kernel\Exception\FireflyException;
use Throwable;

class BusinessException extends FireflyException
{
    public function __construct(
        string $message,
        string $errorCode = 'BUSINESS_ERROR',
        int $httpStatus = 422,
        ErrorCategory $category = ErrorCategory::Business,
        ErrorSeverity $severity = ErrorSeverity::Warning,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $httpStatus, $category, $severity, $previous);
    }
}
