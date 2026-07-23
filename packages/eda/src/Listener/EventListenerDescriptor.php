<?php

declare(strict_types=1);

namespace Firefly\Eda\Listener;

/**
 * A single compiled eda listener: the target class/method plus the event-type patterns it subscribes to and its
 * order. Every field is scalar-or-list so the manifest var_exports as a plain array literal (no closures/objects),
 * loaded by require+map in production. Mirrors ScheduledDescriptor.
 *
 * @phpstan-type EventListenerRow array{class: string, method: string, patterns: list<string>, order: int}
 */
final readonly class EventListenerDescriptor
{
    /**
     * @param  list<string>  $patterns
     */
    public function __construct(
        public string $class,
        public string $method,
        public array $patterns,
        public int $order = 0,
    ) {}

    /**
     * @return EventListenerRow
     */
    public function toArray(): array
    {
        return [
            'class' => $this->class,
            'method' => $this->method,
            'patterns' => $this->patterns,
            'order' => $this->order,
        ];
    }

    /**
     * @param  EventListenerRow  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['class'], $data['method'], $data['patterns'], $data['order']);
    }
}
