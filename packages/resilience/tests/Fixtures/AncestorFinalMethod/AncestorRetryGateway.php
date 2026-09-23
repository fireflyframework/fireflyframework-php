<?php

declare(strict_types=1);

namespace Firefly\Resilience\Tests\Fixtures\AncestorFinalMethod;

use Firefly\Container\Attributes\Service;
use Firefly\Resilience\Method\Retry;

/**
 * A stereotyped subclass of that base with a class-level #[Retry]. `boot()` is final and the author cannot
 * unseal it, so the fan-out skips it; every other public method it exposes — its own AND the base's — is
 * guarded, which is the breadth of `getMethods(IS_PUBLIC)` made visible.
 */
#[Service]
#[Retry('payments')]
class AncestorRetryGateway extends VendorBase
{
    public function own(): void {}
}
