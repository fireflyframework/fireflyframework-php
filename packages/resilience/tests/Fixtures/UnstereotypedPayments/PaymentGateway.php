<?php

declare(strict_types=1);

namespace Firefly\Resilience\Tests\Fixtures\UnstereotypedPayments;

use Firefly\Resilience\Method\Retry;

/**
 * A plain class — no #[Component]-family stereotype, no #[Bean] method returning it — carrying a resilience
 * attribute. Nothing post-processes it, so no proxy wraps it and the retry would never run.
 */
class PaymentGateway
{
    #[Retry('payments')]
    public function charge(string $account): string
    {
        return $account;
    }
}
