<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\Proxy;

use Firefly\Data\Transaction\Attributes\Transactional;
use Firefly\Data\Transaction\Propagation;

/**
 * A routing-observation fixture. Its single transactional method carries a DISTINCTIVE descriptor
 * (REQUIRES_NEW, read-only) so a recording interceptor spy can assert BOTH that it was called AND that the
 * correct per-method descriptor was threaded through. tracked() has no DB side effect: it just doubles its
 * input, so the spy can invoke proceed() without a real transaction. NOT `final` — the proxy extends it.
 */
class InterceptedProbe
{
    #[Transactional(propagation: Propagation::REQUIRES_NEW, readOnly: true)]
    public function tracked(int $n): int
    {
        return $n * 2;
    }
}
