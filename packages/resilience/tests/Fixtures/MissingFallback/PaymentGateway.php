<?php

declare(strict_types=1);

namespace Firefly\Resilience\Tests\Fixtures\MissingFallback;

use Firefly\Container\Attributes\Service;
use Firefly\Resilience\Method\Fallback;
use Firefly\Resilience\Method\Retry;

/**
 * A #[Fallback] naming a method that does not exist. Left to run, it fails with `Call to undefined method`
 * from inside the handler for the outage it was written to absorb, so the scan refuses it instead.
 */
#[Service]
class PaymentGateway
{
    #[Retry('payments')]
    #[Fallback(method: 'nope')]
    public function charge(string $account): string
    {
        return $account;
    }
}
