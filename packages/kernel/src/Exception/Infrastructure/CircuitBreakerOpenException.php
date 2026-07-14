<?php

declare(strict_types=1);

namespace Firefly\Kernel\Exception\Infrastructure;

use Throwable;

class CircuitBreakerOpenException extends InfrastructureException
{
    public function __construct(
        string $message = 'Circuit breaker is open',
        string $errorCode = 'CIRCUIT_BREAKER_OPEN',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, 503, previous: $previous);
    }
}
