<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Event;

/**
 * One constructor parameter of a convention-mapped event, compiled at scan time so that building the event back
 * needs no reflection. `type` is the declared type's name (`string`, `float`, a class), or null for no type or a
 * union of JSON scalars and array, whose stored value is passed through as is (the scanner refuses any other union).
 *
 * @phpstan-type EventParameterRow array{name: string, type: string|null, nullable: bool, optional: bool}
 */
final readonly class EventParameter
{
    public function __construct(
        public string $name,
        public ?string $type,
        public bool $nullable,
        public bool $optional,
    ) {}

    /** @return EventParameterRow */
    public function toArray(): array
    {
        return ['name' => $this->name, 'type' => $this->type, 'nullable' => $this->nullable, 'optional' => $this->optional];
    }

    /** @param EventParameterRow $row */
    public static function fromArray(array $row): self
    {
        return new self($row['name'], $row['type'], $row['nullable'], $row['optional']);
    }
}
