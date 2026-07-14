<?php

declare(strict_types=1);

namespace Firefly\Kernel\Exception\Security;

use Throwable;

class TokenExpiredException extends SecurityException
{
    public function __construct(
        string $message = 'Token expired',
        string $errorCode = 'TOKEN_EXPIRED',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, 401, $previous);
    }
}
