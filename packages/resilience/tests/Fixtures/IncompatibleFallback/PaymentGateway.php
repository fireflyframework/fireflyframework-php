<?php

declare(strict_types=1);

namespace Firefly\Resilience\Tests\Fixtures\IncompatibleFallback;

use Firefly\Container\Attributes\Service;
use Firefly\Resilience\Method\CircuitBreaker;
use Firefly\Resilience\Method\Fallback;
use Throwable;

/**
 * A #[Fallback] whose method requires three parameters for a guarded method that can supply at most two. The
 * cause IS in the appended slot — the parameter right after the guarded method's one argument — so the
 * capacity really is that argument plus the Throwable, and the recovery is still one parameter too wide:
 * this is the fixture for the branch where the `+ 1` applies. (ExtraParameterFallback beside it is the other
 * branch: exactly one parameter too many, and nothing in the slot to fill it.) Reflection can prove the call
 * would fatal with an ArgumentCountError, so the scan refuses it.
 */
#[Service]
class PaymentGateway
{
    #[CircuitBreaker('payments')]
    #[Fallback(method: 'chargeUnavailable')]
    public function charge(string $account): string
    {
        return $account;
    }

    public function chargeUnavailable(string $account, Throwable $cause, string $reason): string
    {
        return 'queued';
    }
}
