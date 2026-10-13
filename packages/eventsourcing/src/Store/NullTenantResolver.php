<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Store;

/** The default resolver: no tenant. Under tenancy that refuses every append and load until you bind a real one. */
final class NullTenantResolver implements TenantResolver
{
    public function currentTenant(): ?string
    {
        return null;
    }
}
