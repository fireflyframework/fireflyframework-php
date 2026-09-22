<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Support;

/** ChainBootTestCase with the AuditInterceptor left UNBOUND: the advice declared itself inert, so the proxy runs a pass-through link. */
abstract class ChainInertBootTestCase extends ChainBootTestCase
{
    protected function bindsAuditInterceptor(): bool
    {
        return false;
    }
}
