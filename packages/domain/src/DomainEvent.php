<?php

declare(strict_types=1);

namespace Firefly\Domain;

use DateTimeImmutable;

/**
 * A flat, immutable domain event. The base supplies a uuid-v4 eventId, an occurredAt timestamp (both settable
 * for reconstitution/testing), and eventType() = the concrete class short name. uuid4() uses random_bytes +
 * string formatting — no ramsey/uuid dependency, no reflection. Subclasses are `final readonly` and call
 * parent::__construct().
 */
abstract readonly class DomainEvent
{
    public string $eventId;

    public DateTimeImmutable $occurredAt;

    public function __construct(?string $eventId = null, ?DateTimeImmutable $occurredAt = null)
    {
        $this->eventId = $eventId ?? self::uuid4();
        $this->occurredAt = $occurredAt ?? new DateTimeImmutable;
    }

    public function eventId(): string
    {
        return $this->eventId;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function eventType(): string
    {
        $class = static::class;
        $pos = strrpos($class, '\\');

        return $pos === false ? $class : substr($class, $pos + 1);
    }

    private static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        /** @var array<int, string> $chunks */
        $chunks = str_split(bin2hex($bytes), 4);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', $chunks);
    }
}
