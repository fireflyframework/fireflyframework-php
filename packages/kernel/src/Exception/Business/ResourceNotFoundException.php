<?php

declare(strict_types=1);

namespace Firefly\Kernel\Exception\Business;

use Throwable;

class ResourceNotFoundException extends BusinessException
{
    public function __construct(
        string $message = 'Resource not found',
        string $errorCode = 'RESOURCE_NOT_FOUND',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, 404, previous: $previous);
    }
}
