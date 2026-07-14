<?php

declare(strict_types=1);

namespace Firefly\Kernel\Exception\Infrastructure;

use Firefly\Kernel\Error\ErrorSeverity;
use Throwable;

class RateLimitExceededException extends InfrastructureException
{
    public function __construct(
        string $message = 'Rate limit exceeded',
        string $errorCode = 'RATE_LIMIT_EXCEEDED',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, 429, ErrorSeverity::Warning, $previous);
    }
}
