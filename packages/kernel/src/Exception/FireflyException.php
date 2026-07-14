<?php

declare(strict_types=1);

namespace Firefly\Kernel\Exception;

use Firefly\Kernel\Error\ErrorCategory;
use Firefly\Kernel\Error\ErrorSeverity;
use RuntimeException;
use Throwable;

/**
 * Base of the LaraFly exception taxonomy. Product-agnostic: every subclass
 * carries a stable string error code, an HTTP status, a category and a severity
 * so the web layer can render a consistent RFC-7807 response.
 */
class FireflyException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly int $httpStatus = 500,
        private readonly ErrorCategory $category = ErrorCategory::Internal,
        private readonly ErrorSeverity $severity = ErrorSeverity::Error,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function category(): ErrorCategory
    {
        return $this->category;
    }

    public function severity(): ErrorSeverity
    {
        return $this->severity;
    }
}
