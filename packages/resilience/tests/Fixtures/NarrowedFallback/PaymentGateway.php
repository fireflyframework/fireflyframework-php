<?php

declare(strict_types=1);

namespace Firefly\Resilience\Tests\Fixtures\NarrowedFallback;

use Firefly\Container\Attributes\Service;
use Firefly\Resilience\Exception\BulkheadFullException;
use Firefly\Resilience\Method\Bulkhead;
use Firefly\Resilience\Method\Fallback;
use RuntimeException;

/**
 * A #[Fallback] that NARROWS what it recovers. The default is `[Throwable::class]` — everything — and the
 * list is the author's statement that a programming error must keep propagating while a saturated pool is
 * absorbed, so it has to reach the compiled row verbatim and in order rather than being normalised to the
 * default by an untested branch.
 */
#[Service]
class PaymentGateway
{
    #[Bulkhead('payments')]
    #[Fallback(method: 'chargeUnavailable', on: [BulkheadFullException::class, RuntimeException::class])]
    public function charge(string $account): string
    {
        return $account;
    }

    public function chargeUnavailable(string $account): string
    {
        return 'queued';
    }
}
