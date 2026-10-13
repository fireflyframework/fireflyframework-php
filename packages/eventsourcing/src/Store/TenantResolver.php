<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Store;

/** The tenant of the current request, for firefly.eventsourcing.tenancy.enabled. Bind your own; the default has none. */
interface TenantResolver
{
    public function currentTenant(): ?string;
}
