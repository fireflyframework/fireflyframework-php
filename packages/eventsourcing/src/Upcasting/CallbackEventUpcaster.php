<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Upcasting;

use Closure;
use Firefly\EventSourcing\Event\StoredEvent;

/** An upcaster from a closure over the payload: to fromVersion + 1 unless $toVersion says otherwise, renamed when $toType is given. */
final class CallbackEventUpcaster implements EventUpcaster
{
    /**
     * @param  Closure(array<string, mixed>, StoredEvent): array<string, mixed>  $transform
     */
    public function __construct(
        private readonly string $eventType,
        private readonly int $fromVersion,
        private readonly Closure $transform,
        private readonly ?int $toVersion = null,
        private readonly ?string $toType = null,
        private readonly int $priority = 0,
    ) {}

    public function eventType(): string
    {
        return $this->eventType;
    }

    public function fromVersion(): int
    {
        return $this->fromVersion;
    }

    public function priority(): int
    {
        return $this->priority;
    }

    public function upcast(StoredEvent $event): StoredEvent
    {
        return $event->upcasted($this->toType ?? $event->eventType, $this->toVersion ?? $this->fromVersion + 1, ($this->transform)($event->payload, $event));
    }
}
