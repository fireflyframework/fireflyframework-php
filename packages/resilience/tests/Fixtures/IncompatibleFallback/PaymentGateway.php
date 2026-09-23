<?php

declare(strict_types=1);

namespace Firefly\Resilience\Tests\Fixtures\IncompatibleFallback;

use Firefly\Container\Attributes\Service;
use Firefly\Resilience\Method\CircuitBreaker;
use Firefly\Resilience\Method\Fallback;
use Throwable;

/**
 * A #[Fallback] whose method requires three parameters for a guarded method that can supply at most two. Its
 * last parameter IS a Throwable, so the capacity really is the one argument plus the cause — this is the
 * fixture for the branch where the `+ 1` applies and the recovery is still too wide. (ExtraParameterFallback
 * beside it is the other branch: exactly one parameter too many, and no Throwable to fill it.) Reflection
 * can prove the call would fatal, so the scan refuses it.
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

    public function chargeUnavailable(string $account, string $reason, Throwable $cause): string
    {
        return 'queued';
    }
}
