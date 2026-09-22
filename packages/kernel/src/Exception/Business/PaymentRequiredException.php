<?php

declare(strict_types=1);

namespace Firefly\Kernel\Exception\Business;

use Throwable;

/**
 * 402 — the caller's plan, edition or balance does not include what was asked for.
 *
 * A sales event, not a fault, and not a permission problem either: a 403 says "you are not allowed", which
 * a person reads as a mistake in their role, while a 402 says "this is not included", which they read as a
 * thing to buy. Conflating the two is how a customer files a support ticket for an upsell. It sits under
 * Business (with Warning severity) because nothing is broken — the request was well-formed, authenticated
 * and understood, and the answer is a commercial one.
 */
class PaymentRequiredException extends BusinessException
{
    public function __construct(
        string $message = 'Payment required',
        string $errorCode = 'PAYMENT_REQUIRED',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, 402, previous: $previous);
    }
}
