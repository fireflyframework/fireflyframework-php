<?php

declare(strict_types=1);

namespace Firefly\Resilience\Tests\Fixtures\ExtraParameterFallback;

use Firefly\Container\Attributes\Service;
use Firefly\Resilience\Method\Fallback;
use Firefly\Resilience\Method\Retry;

/**
 * The off-by-one the arity proof used to wave through: the recovery requires exactly ONE more parameter than
 * the guarded method has, and its last parameter is an `int` rather than a Throwable — so the interceptor
 * never appends the cause and the call it really makes is `queued('acct')`, an `ArgumentCountError` raised
 * from inside the catch that was absorbing the outage. An unconditional `+ 1` capacity accepted this.
 */
#[Service]
class PaymentGateway
{
    #[Retry('payments')]
    #[Fallback(method: 'queued')]
    public function charge(string $account): string
    {
        return $account;
    }

    public function queued(string $account, int $reasonCode): string
    {
        return 'queued';
    }
}
