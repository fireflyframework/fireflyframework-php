<?php

declare(strict_types=1);

namespace Firefly\Resilience\Tests\Fixtures\FinalPayments;

use Firefly\Container\Attributes\Service;
use Firefly\Resilience\Method\Retry;

/**
 * A stereotyped bean the advice would have to proxy while being `final`: a proxy must extend it, so the scan
 * refuses the rule rather than let it compile into a plan nothing can apply.
 */
#[Service]
final class PaymentGateway
{
    #[Retry('payments')]
    public function charge(string $account): string
    {
        return $account;
    }
}
