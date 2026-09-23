<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\ProxyFinalMethod;

use Firefly\Data\Transaction\Attributes\Transactional;

/**
 * A non-final bean with a `final` public method, planned by the CLASS-LEVEL #[Transactional] that fans onto
 * every public method. The generated proxy renders an override for every planned method, so PHP refuses the
 * whole class at load — "Cannot override final method", from inside a generated file, naming nothing that
 * points back here. The same guard catches a method-level attribute written directly on a `final` method:
 * signatures() asks the method, not the attribute. Isolated in its OWN directory so scanning it does not
 * poison the other proxy fixtures.
 */
#[Transactional]
class SealedMethodService
{
    public function open(): void {}

    final public function sealed(): void {}
}
