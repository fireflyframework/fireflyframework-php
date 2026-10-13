<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Event;

/**
 * What an event carries besides its payload: the correlation id of the request or message that caused it, the id
 * of the event that caused it (causation), the tenant, the user, and any extra string headers. Every member is a
 * string because the stored form IS the eda envelope's header map. That is also why the correlation id travels as
 * `x-correlation-id`, the header the eda-database outbox already reads its transaction id from.
 */
final readonly class EventMetadata
{
    public const string CORRELATION_ID = 'x-correlation-id';

    public const string CAUSATION_ID = 'x-causation-id';

    public const string TENANT_ID = 'x-tenant-id';

    public const string USER_ID = 'x-user-id';

    /**
     * @param  array<string, string>  $extra
     */
    public function __construct(
        public ?string $correlationId = null,
        public ?string $causationId = null,
        public ?string $tenantId = null,
        public ?string $userId = null,
        public array $extra = [],
    ) {}

    /** The members $over sets win; extras are merged, $over's on top. */
    public function merge(self $over): self
    {
        return new self(
            $over->correlationId ?? $this->correlationId,
            $over->causationId ?? $this->causationId,
            $over->tenantId ?? $this->tenantId,
            $over->userId ?? $this->userId,
            array_merge($this->extra, $over->extra),
        );
    }

    public function withTenantId(?string $tenantId): self
    {
        return new self($this->correlationId, $this->causationId, $tenantId, $this->userId, $this->extra);
    }

    public function withUserId(?string $userId): self
    {
        return new self($this->correlationId, $this->causationId, $this->tenantId, $userId, $this->extra);
    }

    public function withCorrelationId(?string $correlationId): self
    {
        return new self($correlationId, $this->causationId, $this->tenantId, $this->userId, $this->extra);
    }

    /** Metadata for an event appended while handling $cause: same correlation (or the cause itself), caused by it. */
    public static function causedBy(StoredEvent $cause): self
    {
        return new self($cause->metadata->correlationId ?? $cause->eventId, $cause->eventId, $cause->metadata->tenantId);
    }

    /** @return array<string, string> */
    public function toHeaders(): array
    {
        $headers = $this->extra;
        foreach ([self::CORRELATION_ID => $this->correlationId, self::CAUSATION_ID => $this->causationId, self::TENANT_ID => $this->tenantId, self::USER_ID => $this->userId] as $name => $value) {
            if ($value !== null) {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    /** @param array<string, string> $headers */
    public static function fromHeaders(array $headers): self
    {
        $extra = $headers;
        unset($extra[self::CORRELATION_ID], $extra[self::CAUSATION_ID], $extra[self::TENANT_ID], $extra[self::USER_ID]);

        return new self(
            $headers[self::CORRELATION_ID] ?? null,
            $headers[self::CAUSATION_ID] ?? null,
            $headers[self::TENANT_ID] ?? null,
            $headers[self::USER_ID] ?? null,
            $extra,
        );
    }
}
