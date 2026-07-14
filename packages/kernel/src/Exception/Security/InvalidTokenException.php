<?php

declare(strict_types=1);

namespace Firefly\Kernel\Exception\Security;

use Throwable;

class InvalidTokenException extends SecurityException
{
    public function __construct(
        string $message = 'Invalid token',
        string $errorCode = 'INVALID_TOKEN',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, 401, $previous);
    }
}
