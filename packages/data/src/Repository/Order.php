<?php

declare(strict_types=1);

namespace Firefly\Data\Repository;

/**
 * A single ordering: a property name and a direction. Pure value object, mapped to `orderBy` at the Eloquent edge.
 */
final readonly class Order
{
    public function __construct(
        public string $property,
        public Direction $direction = Direction::Asc,
    ) {}

    public static function asc(string $property): self
    {
        return new self($property, Direction::Asc);
    }

    public static function desc(string $property): self
    {
        return new self($property, Direction::Desc);
    }

    public function isAscending(): bool
    {
        return $this->direction === Direction::Asc;
    }
}
