<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\ProxyAncestorFinalMethod;

use Firefly\Data\Transaction\Attributes\Transactional;

/**
 * The leaf the plan is keyed by, and the file an author reading the refusal will open first. The `final` it is
 * about is not in here — which is the whole point of the message naming `VendorBase::register() (planned via
 * AncestorSealedService)` rather than `AncestorSealedService::register()`. Isolated in its own directory so
 * scanning it does not poison the other proxy fixtures.
 */
#[Transactional]
class AncestorSealedService extends VendorBase
{
    public function own(): void {}
}
