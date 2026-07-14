<?php

declare(strict_types=1);

namespace Firefly\Kernel\Exception\Security;

use Throwable;

class AuthorizationException extends SecurityException
{
    public function __construct(
        string $message = 'Access denied',
        string $errorCode = 'ACCESS_DENIED',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, 403, $previous);
    }
}
