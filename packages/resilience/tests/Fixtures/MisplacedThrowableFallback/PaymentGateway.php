<?php

declare(strict_types=1);

namespace Firefly\Resilience\Tests\Fixtures\MisplacedThrowableFallback;

use Firefly\Container\Attributes\Service;
use Firefly\Resilience\Method\Fallback;
use Firefly\Resilience\Method\Retry;
use Throwable;

/**
 * The same misplacement at full width: the recovery declares the cause FIRST and then the guarded arguments,
 * so it is exactly as wide as the guarded method and every count the scan could compare agrees. The
 * interceptor's call is `queued('acct', 42)` — the account lands in `$cause` and the TypeError arrives from
 * inside the catch. Only the POSITION tells the two signatures apart, which is why the proof asks about the
 * slot rather than about the width.
 */
#[Service]
class PaymentGateway
{
    #[Retry('payments')]
    #[Fallback(method: 'queued')]
    public function charge(string $account, int $cents): string
    {
        return $account;
    }

    public function queued(Throwable $cause, string $account): string
    {
        return 'queued';
    }
}
