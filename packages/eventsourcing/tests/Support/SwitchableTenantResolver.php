<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Tests\Support;

use Firefly\EventSourcing\Store\TenantResolver;

/** A TenantResolver whose current tenant a test switches between calls, including to none. */
final class SwitchableTenantResolver implements TenantResolver
{
    public function __construct(public ?string $tenant = null) {}

    public function currentTenant(): ?string
    {
        return $this->tenant;
    }
}
