<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Store;

use Firefly\EventSourcing\Exception\TenantRequiredException;

/**
 * firefly.eventsourcing.tenancy.enabled: every append is stamped with the TenantResolver's tenant, and a stream
 * whose events name another tenant reads as absent. Appending to such a stream, or appending events that already
 * name another tenant, is refused. With no current tenant, both reads and writes are refused.
 *
 * The global stream (readAll, currentPosition) is NOT scoped. Projections are system processes that follow every
 * tenant and filter with EventFilter::$tenantId when they want one. Aggregate ids stay unique across tenants, so
 * use UUIDs.
 */
final class TenantScopedEventStore implements EventStore
{
    public function __construct(
        private readonly EventStore $inner,
        private readonly TenantResolver $tenants,
    ) {}

    public function inner(): EventStore
    {
        return $this->inner;
    }

    public function append(string $aggregateId, string $aggregateType, array $events, int $expectedVersion = ExpectedVersion::ANY): array
    {
        $tenant = $this->tenant();
        $scoped = [];
        foreach ($events as $event) {
            if ($event->metadata->tenantId !== null && $event->metadata->tenantId !== $tenant) {
                throw TenantRequiredException::foreign($aggregateId);
            }
            $scoped[] = $event->withMetadata($event->metadata->withTenantId($tenant));
        }
        if (! $this->owned($aggregateId, $tenant)) {
            throw TenantRequiredException::foreign($aggregateId);
        }

        return $this->inner->append($aggregateId, $aggregateType, $scoped, $expectedVersion);
    }

    public function load(string $aggregateId, int $fromVersion = 1, ?int $toVersion = null): EventStream
    {
        $tenant = $this->tenant();
        if (! $this->owned($aggregateId, $tenant)) {
            return new EventStream($aggregateId, []);
        }

        return $this->inner->load($aggregateId, $fromVersion, $toVersion);
    }

    public function version(string $aggregateId): int
    {
        return $this->owned($aggregateId, $this->tenant()) ? $this->inner->version($aggregateId) : 0;
    }

    public function exists(string $aggregateId): bool
    {
        return $this->version($aggregateId) > 0;
    }

    public function readAll(int $afterPosition = 0, int $limit = 500, ?EventFilter $filter = null): array
    {
        return $this->inner->readAll($afterPosition, $limit, $filter);
    }

    public function currentPosition(): int
    {
        return $this->inner->currentPosition();
    }

    public function isHealthy(): bool
    {
        return $this->inner->isHealthy();
    }

    private function tenant(): string
    {
        $tenant = $this->tenants->currentTenant();

        return $tenant === null || $tenant === '' ? throw TenantRequiredException::missing() : $tenant;
    }

    /** A stream with no events is anybody's; otherwise its first event names its tenant. */
    private function owned(string $aggregateId, string $tenant): bool
    {
        $first = $this->inner->load($aggregateId, 1, 1)->events[0] ?? null;

        return $first === null || $first->metadata->tenantId === $tenant;
    }
}
