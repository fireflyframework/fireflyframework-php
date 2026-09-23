<?php

declare(strict_types=1);

namespace Firefly\Resilience\Tests\Fixtures\MagicMethod;

use Firefly\Container\Attributes\Service;
use Firefly\Resilience\Method\Retry;

/**
 * The single-action-service shape, which is mainstream: one #[Service], one `__invoke()`. The attribute is
 * written HERE, by hand, about THIS method — so dropping it is not a skip, it is the silent no-op this scan
 * exists to refuse. (The class-level fan-out reaching `__invoke` is a different case and stays silent; see
 * ClassLevelPayments.)
 */
#[Service]
class InvokableRetryGateway
{
    #[Retry('payments')]
    public function __invoke(): void {}
}
