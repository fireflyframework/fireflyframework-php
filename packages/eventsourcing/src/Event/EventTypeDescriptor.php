<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Event;

use Firefly\Domain\DomainEvent;

/**
 * One registered event type. A `serializable` class maps itself (SerializableEvent); any other class is mapped by
 * convention from its public properties to `parameters`.
 *
 * @phpstan-import-type EventParameterRow from EventParameter
 *
 * @phpstan-type EventTypeRow array{class: class-string<DomainEvent>, type: string, version: int, serializable: bool, parameters: list<EventParameterRow>}
 */
final readonly class EventTypeDescriptor
{
    /**
     * @param  class-string<DomainEvent>  $class
     * @param  list<EventParameter>  $parameters
     */
    public function __construct(
        public string $class,
        public string $type,
        public int $version = 1,
        public bool $serializable = false,
        public array $parameters = [],
    ) {}

    /** @return EventTypeRow */
    public function toArray(): array
    {
        return [
            'class' => $this->class,
            'type' => $this->type,
            'version' => $this->version,
            'serializable' => $this->serializable,
            'parameters' => array_map(static fn (EventParameter $parameter): array => $parameter->toArray(), $this->parameters),
        ];
    }

    /** @param EventTypeRow $row */
    public static function fromArray(array $row): self
    {
        return new self($row['class'], $row['type'], $row['version'], $row['serializable'], array_map(static fn (array $parameter): EventParameter => EventParameter::fromArray($parameter), $row['parameters']));
    }
}
