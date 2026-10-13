<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Event;

use Firefly\Domain\DomainEvent;

/**
 * An event that maps itself to and from its stored payload: implement this when the convention mapper (public
 * properties to named constructor arguments) does not fit — a value object it cannot build, payload names of your
 * own, a payload that must survive JSON byte for byte.
 */
interface SerializableEvent
{
    /** @return array<string, mixed> */
    public function toPayload(): array;

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload, StoredEvent $event): DomainEvent;
}
