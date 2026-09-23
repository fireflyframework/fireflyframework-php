<?php

declare(strict_types=1);

namespace Firefly\Resilience\Tests\Fixtures\OverriddenBase;

use Firefly\Container\Attributes\Service;

/** The bean, overriding the annotated method without carrying the attribute over. */
#[Service]
class StripeGateway extends BaseGateway
{
    public function charge(string $account): string
    {
        return strtoupper($account);
    }
}
