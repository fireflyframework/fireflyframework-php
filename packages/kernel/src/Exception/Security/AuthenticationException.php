<?php

declare(strict_types=1);

namespace Firefly\Kernel\Exception\Security;

use Throwable;

class AuthenticationException extends SecurityException
{
    public function __construct(
        string $message = 'Authentication failed',
        string $errorCode = 'AUTHENTICATION_FAILED',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, 401, $previous);
    }
}
