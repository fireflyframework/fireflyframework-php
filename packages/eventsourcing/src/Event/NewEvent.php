<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Event;

use DateTimeImmutable;

/**
 * An event on its way into a store: a type and schema version, a JSON-able payload, metadata, an id (uuid v4 unless
 * given) and the instant it occurred. The repository builds these from DomainEvents; code that appends directly
 * (an import, the orchestration provider) builds them by hand.
 */
final readonly class NewEvent
{
    public string $eventId;

    public DateTimeImmutable $occurredAt;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $eventType,
        public array $payload,
        public int $eventVersion = 1,
        public EventMetadata $metadata = new EventMetadata,
        ?string $eventId = null,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        $this->eventId = $eventId ?? self::uuid4();
        $this->occurredAt = $occurredAt ?? new DateTimeImmutable;
    }

    public function withMetadata(EventMetadata $metadata): self
    {
        return new self($this->eventType, $this->payload, $this->eventVersion, $metadata, $this->eventId, $this->occurredAt);
    }

    /** random_bytes + formatting, no ramsey/uuid: the DomainEvent and EventEnvelope precedent. */
    public static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        /** @var array<int, string> $chunks */
        $chunks = str_split(bin2hex($bytes), 4);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', $chunks);
    }
}
