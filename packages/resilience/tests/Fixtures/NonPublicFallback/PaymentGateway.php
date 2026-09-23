<?php

declare(strict_types=1);

namespace Firefly\Resilience\Tests\Fixtures\NonPublicFallback;

use Firefly\Container\Attributes\Service;
use Firefly\Resilience\Method\Fallback;
use Firefly\Resilience\Method\Retry;

/**
 * A #[Fallback] naming a method that exists, has a compatible signature — and is `protected`. The recovery is
 * invoked as `[$bean, $method](...)` from inside the interceptor, which is a call from OUTSIDE the class, so
 * PHP raises `Error: Call to protected method` from within the very catch that was absorbing the outage.
 * `hasMethod()` cannot tell the two apart; `isPublic()` can, so the scan refuses it here.
 */
#[Service]
class PaymentGateway
{
    #[Retry('payments')]
    #[Fallback(method: 'chargeUnavailable')]
    public function charge(string $account): string
    {
        return $account;
    }

    protected function chargeUnavailable(string $account): string
    {
        return 'queued';
    }
}
