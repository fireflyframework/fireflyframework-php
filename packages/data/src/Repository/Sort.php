<?php

declare(strict_types=1);

namespace Firefly\Data\Repository;

/**
 * An ordered list of Order value objects. Immutable: every combinator returns a new Sort. `by(...)` builds an
 * ascending sort over the given properties; `and()` concatenates; `ascending()`/`descending()` rewrite every
 * order's direction. Mapped to a chain of `orderBy` calls at the Eloquent edge (EloquentRepository::applySort).
 */
final readonly class Sort
{
    /**
     * @param  list<Order>  $orders
     */
    public function __construct(public array $orders = []) {}

    public static function unsorted(): self
    {
        return new self([]);
    }

    public static function by(string ...$properties): self
    {
        return new self(array_map(
            static fn (string $property): Order => Order::asc($property),
            array_values($properties),
        ));
    }

    public function and(self $other): self
    {
        return new self([...$this->orders, ...$other->orders]);
    }

    public function ascending(): self
    {
        return new self(array_map(
            static fn (Order $order): Order => Order::asc($order->property),
            $this->orders,
        ));
    }

    public function descending(): self
    {
        return new self(array_map(
            static fn (Order $order): Order => Order::desc($order->property),
            $this->orders,
        ));
    }

    public function isSorted(): bool
    {
        return $this->orders !== [];
    }
}
