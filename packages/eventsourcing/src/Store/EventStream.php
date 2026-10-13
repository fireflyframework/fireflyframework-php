<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Store;

use Firefly\EventSourcing\Event\StoredEvent;

/** One aggregate's events, in version order. */
final readonly class EventStream
{
    /**
     * @param  list<StoredEvent>  $events
     */
    public function __construct(
        public string $aggregateId,
        public array $events,
    ) {}

    public function version(): int
    {
        return $this->events === [] ? 0 : $this->events[count($this->events) - 1]->aggregateVersion;
    }

    public function isEmpty(): bool
    {
        return $this->events === [];
    }

    public function aggregateType(): ?string
    {
        return $this->events === [] ? null : $this->events[0]->aggregateType;
    }
}
