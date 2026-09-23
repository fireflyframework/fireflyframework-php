<?php

declare(strict_types=1);

namespace Firefly\Resilience\Tests\Fixtures\CauseSlotFallback;

use Firefly\Container\Attributes\Service;
use Firefly\Resilience\Method\Fallback;
use Firefly\Resilience\Method\Retry;
use Throwable;

/**
 * The two shapes that pin `fallbackAcceptsThrowable` to the SLOT the interceptor fills rather than to a
 * position in the recovery's own signature — one where the Throwable is the LAST parameter and the answer is
 * still FALSE, one where it is the FIRST and the answer is TRUE.
 *
 *   - `charge()` takes one argument and `queued()` is three parameters wide: the cause would land in
 *     `$note`, so it is not appended at all and the recovery is called with the guarded arguments alone,
 *     which its defaults accept. Reading the LAST parameter here answered TRUE and produced
 *     `queued('acct', $cause)` — a TypeError, raised from inside the catch.
 *   - `reconcile()` takes NO arguments, so the appended slot is the recovery's first parameter and the cause
 *     really is the only thing `reconcileQueued()` is ever handed.
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

    public function queued(string $account, ?string $note = null, ?Throwable $cause = null): string
    {
        return 'queued';
    }

    #[Retry('payments')]
    #[Fallback(method: 'reconcileQueued')]
    public function reconcile(): string
    {
        return 'reconciled';
    }

    public function reconcileQueued(Throwable $cause): string
    {
        return 'queued';
    }
}
