<?php

declare(strict_types=1);

namespace Firefly\Callbacks\Delivery;

use RuntimeException;

/**
 * A status worth another attempt (408, 429, 5xx), thrown INSIDE the circuit breaker's call so the breaker counts it
 * as a failure of the endpoint; a 4xx that is the receiver's final word is returned instead and counts as the
 * endpoint working.
 */
final class RetryableDeliveryFailure extends RuntimeException
{
    public function __construct(public readonly int $status)
    {
        parent::__construct("HTTP {$status}");
    }
}
