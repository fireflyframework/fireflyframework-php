<?php

declare(strict_types=1);

namespace Firefly\Observability\Tests\Fixtures\AncestorFinalMethod;

use Firefly\Container\Attributes\Service;
use Firefly\Observability\Method\Timed;

/**
 * A stereotyped subclass of that base with a class-level #[Timed]. `boot()` is final and the author cannot
 * unseal it, so the fan-out skips it; every other public method it exposes — its own AND the base's — is
 * timed, which is the breadth of `getMethods(IS_PUBLIC)` made visible.
 */
#[Service]
#[Timed('vendor.svc')]
class AncestorTimedService extends VendorBase
{
    public function own(): void {}
}
