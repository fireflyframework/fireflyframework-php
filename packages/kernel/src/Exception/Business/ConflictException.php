<?php

declare(strict_types=1);

namespace Firefly\Kernel\Exception\Business;

use Throwable;

class ConflictException extends BusinessException
{
    public function __construct(
        string $message = 'Conflict',
        string $errorCode = 'CONFLICT',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, 409, previous: $previous);
    }
}
