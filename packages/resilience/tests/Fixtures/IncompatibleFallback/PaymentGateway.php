<?php

declare(strict_types=1);

namespace Firefly\Resilience\Tests\Fixtures\IncompatibleFallback;

use Firefly\Container\Attributes\Service;
use Firefly\Resilience\Method\CircuitBreaker;
use Firefly\Resilience\Method\Fallback;

/**
 * A #[Fallback] whose method requires three parameters for a guarded method that can supply at most two (its
 * one argument, plus the Throwable). Reflection can prove the call would fatal, so the scan refuses it.
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

    public function chargeUnavailable(string $account, string $reason, int $attempt): string
    {
        return 'queued';
    }
}
