<?php

declare(strict_types=1);

namespace Firefly\Kernel\Exception\Infrastructure;

use Throwable;

class DataAccessException extends InfrastructureException
{
    public function __construct(
        string $message = 'Data access error',
        string $errorCode = 'DATA_ACCESS_ERROR',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, 500, previous: $previous);
    }
}
