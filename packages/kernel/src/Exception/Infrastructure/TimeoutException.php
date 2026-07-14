<?php

declare(strict_types=1);

namespace Firefly\Kernel\Exception\Infrastructure;

use Throwable;

class TimeoutException extends InfrastructureException
{
    public function __construct(
        string $message = 'Operation timed out',
        string $errorCode = 'TIMEOUT',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, 504, previous: $previous);
    }
}
