<?php

declare(strict_types=1);

namespace Firefly\Web\Exception;

use Firefly\Kernel\Error\ErrorCategory;
use Firefly\Kernel\Error\ErrorSeverity;
use Firefly\Kernel\Exception\FireflyException;
use Throwable;

/**
 * A malformed request the web layer rejects before invoking the controller: a missing required parameter
 * (MISSING_PARAMETER) or an uncoercible scalar (TYPE_CONVERSION_ERROR). HTTP 400, category Validation — so
 * the RFC-7807 renderer maps it exactly like any other FireflyException (status lives on the exception).
 */
class InvalidRequestException extends FireflyException
{
    public function __construct(
        string $message,
        string $errorCode = 'INVALID_REQUEST',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, 400, ErrorCategory::Validation, ErrorSeverity::Warning, $previous);
    }
}
