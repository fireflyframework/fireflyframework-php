<?php

declare(strict_types=1);

namespace App\Orders;

use JsonSerializable;

/**
 * An order: who placed it, where it ships, and what is on it.
 *
 * IMPLEMENTS JsonSerializable ON PURPOSE. A controller action may return any value; the ResponseFactory
 * hands it to the negotiated MessageConverter, and the JSON converter honours JsonSerializable natively. So
 * the wire shape of an order is declared ONCE, here, next to the data — every action that returns an order
 * gets the same representation, and adding a field cannot leave one endpoint out of step with another. Note
 * that `total` is part of that shape even though it is a method: json_encode() only sees public properties,
 * so a derived value has to be published deliberately.
 *
 * The id is nullable because an Order exists before it is stored — `OrderController::store()` builds one
 * with no id, and the id is assigned by the database when OrderService writes the row. Modelling "not yet
 * persisted" as a null id rather than as a second class keeps one type in play across the whole slice.
 */
final readonly class Order implements JsonSerializable
{
    /**
     * @param  list<OrderLine>  $lines
     */
    public function __construct(
        public ?int $id,
        public string $customer,
        public string $email,
        public Address $shipTo,
        public array $lines,
    ) {}

    /** The order's value, derived from its lines rather than stored beside them. */
    public function total(): float
    {
        return round(array_sum(array_map(static fn (OrderLine $line): float => $line->subtotal(), $this->lines)), 2);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'customer' => $this->customer,
            'email' => $this->email,
            'shipTo' => $this->shipTo,
            'lines' => $this->lines,
            'total' => $this->total(),
        ];
    }
}
