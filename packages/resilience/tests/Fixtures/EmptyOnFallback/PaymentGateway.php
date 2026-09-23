<?php

declare(strict_types=1);

namespace Firefly\Resilience\Tests\Fixtures\EmptyOnFallback;

use Firefly\Container\Attributes\Service;
use Firefly\Resilience\Method\Fallback;
use Firefly\Resilience\Method\Retry;

/**
 * The one shape of `on:` the per-entry proof cannot see: an EMPTY list is validated by refusing nothing, and
 * it compiles into a row whose `$cause instanceof` test matches no throwable at all. The fallback loads, the
 * interceptor composes it, and the guarded call then fails exactly as though no #[Fallback] had been
 * written — the same silent no-op as a misspelt entry, one step further left.
 */
#[Service]
class PaymentGateway
{
    #[Retry('payments')]
    #[Fallback(method: 'queued', on: [])]
    public function charge(string $account): string
    {
        return $account;
    }

    public function queued(string $account): string
    {
        return 'queued';
    }
}
