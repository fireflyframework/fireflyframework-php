<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Exception;

/** firefly.eventsourcing.tenancy.enabled is on and the TenantResolver has no tenant, or the stream is another tenant's. */
final class TenantRequiredException extends EventSourcingException
{
    public static function missing(): self
    {
        return new self('Event-store tenancy is enabled but the TenantResolver returned no tenant for this request.', 'EVENT_STORE_TENANT_REQUIRED', 403);
    }

    public static function foreign(string $aggregateId): self
    {
        return new self("The events given for aggregate [{$aggregateId}] name another tenant.", 'EVENT_STORE_TENANT_MISMATCH', 403);
    }
}
