<?php

declare(strict_types=1);

namespace Firefly\Kernel\Exception\External;

use Throwable;

class IntegrationException extends ExternalServiceException
{
    public function __construct(
        string $message = 'Integration error',
        string $errorCode = 'INTEGRATION_ERROR',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, 502, $previous);
    }
}
