<?php

declare(strict_types=1);

namespace App\Orders;

use JsonSerializable;

/**
 * An order: who placed it, where it ships, what is on it, and what it comes to.
 *
 * `total` is derived from the lines rather than stored beside them, and is never accepted from a client.
 */
final readonly class Order implements JsonSerializable
{
    // NOTE — THE DOCBLOCK ABOVE IS PUBLISHED, THIS COMMENT IS NOT. firefly/openapi uses a class docblock as
    // the `description` of the component schema it generates, so what is written up there is read by whoever
    // consumes this API. Notes about how the framework treats this class belong here, where they are
    // invisible to the generator.
    //
    // IMPLEMENTS JsonSerializable ON PURPOSE. A controller action may return any value; the ResponseFactory
    // hands it to the negotiated MessageConverter, and the JSON converter honours JsonSerializable natively.
    // So the wire shape of an order is declared ONCE, below, next to the data — every action that returns an
    // order gets the same representation, and adding a field cannot leave one endpoint out of step with
    // another.
    //
    // WHICH IS ALSO WHY jsonSerialize() CARRIES AN `@return array{...}`. firefly/openapi documents a returned
    // class from its wire shape, and for a JsonSerializable class the wire shape is what that method returns
    // — which here is NOT the property list, because `total` is a derived method. Reflection alone would
    // publish five of the six members that actually appear in the response. The array shape states all six,
    // PHPStan checks it against the method on every build, and the generator reads it. Delete it and `total`
    // silently disappears from /openapi.json while the API keeps sending it.
    //
    // The id is nullable because an Order exists before it is stored — `OrderController::store()` builds one
    // with no id, and the id is assigned by the database when OrderService writes the row. Modelling "not yet
    // persisted" as a null id rather than as a second class keeps one type in play across the whole slice.

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

    /** @return array{id: int|null, customer: string, email: string, shipTo: Address, lines: list<OrderLine>, total: float} */
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
