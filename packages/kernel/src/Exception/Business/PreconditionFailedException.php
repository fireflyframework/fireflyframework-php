<?php

declare(strict_types=1);

namespace Firefly\Kernel\Exception\Business;

use Throwable;

class PreconditionFailedException extends BusinessException
{
    public function __construct(
        string $message = 'Precondition failed',
        string $errorCode = 'PRECONDITION_FAILED',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, 412, previous: $previous);
    }
}
