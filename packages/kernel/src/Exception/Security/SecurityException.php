<?php

declare(strict_types=1);

namespace Firefly\Kernel\Exception\Security;

use Firefly\Kernel\Error\ErrorCategory;
use Firefly\Kernel\Error\ErrorSeverity;
use Firefly\Kernel\Exception\FireflyException;
use Throwable;

class SecurityException extends FireflyException
{
    public function __construct(
        string $message,
        string $errorCode = 'SECURITY_ERROR',
        int $httpStatus = 403,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $httpStatus, ErrorCategory::Security, ErrorSeverity::Warning, $previous);
    }
}
