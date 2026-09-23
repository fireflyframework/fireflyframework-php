<?php

declare(strict_types=1);

namespace Firefly\Resilience\Tests\Fixtures\LonelyFallback;

use Firefly\Container\Attributes\Service;
use Firefly\Resilience\Method\Fallback;

/**
 * A #[Fallback] with nothing to fall back FROM: no retry gave up, no breaker refused, no bulkhead filled. The
 * proxy would exist only to install a try/catch the author could write in the method, where a reader of the
 * class can see it — so the scan refuses it rather than hide control flow behind an attribute.
 */
#[Service]
class PaymentGateway
{
    #[Fallback(method: 'chargeUnavailable')]
    public function charge(string $account): string
    {
        return $account;
    }

    public function chargeUnavailable(string $account): string
    {
        return 'queued';
    }
}
