<?php

declare(strict_types=1);

namespace Firefly\Observability\Tests\Fixtures\OverriddenBase;

use Firefly\Container\Attributes\Service;

/** The bean, overriding the annotated method without carrying the attribute over. */
#[Service]
class StripeGateway extends BaseGateway
{
    public function charge(int $cents): int
    {
        return $cents * 2;
    }
}
