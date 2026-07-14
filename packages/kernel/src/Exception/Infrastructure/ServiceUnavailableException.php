<?php

declare(strict_types=1);

namespace Firefly\Kernel\Exception\Infrastructure;

use Throwable;

class ServiceUnavailableException extends InfrastructureException
{
    public function __construct(
        string $message = 'Service unavailable',
        string $errorCode = 'SERVICE_UNAVAILABLE',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, 503, previous: $previous);
    }
}
