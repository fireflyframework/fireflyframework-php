<?php

declare(strict_types=1);

namespace App\Orders;

/**
 * One line of an order: what was bought, how many, and at what unit price.
 */
final readonly class OrderLine
{
    // NOTE — THE DOCBLOCK ABOVE IS PUBLISHED as this component's `description` in /openapi.json; this comment
    // is not. See App\Orders\Order for the rule.
    //
    // `subtotal()` is here rather than in the controller or the service because it is a fact about a line,
    // not about a request or a use case — the small habit that keeps a domain model from decaying into a bag
    // of public properties with all the behaviour somewhere else. It is deliberately NOT part of the wire
    // shape: this class is not JsonSerializable, so json_encode() emits its public properties and a client
    // computes its own line totals from them.

    public function __construct(
        public string $sku,
        public int $quantity,
        public float $unitPrice,
    ) {}

    public function subtotal(): float
    {
        return round($this->quantity * $this->unitPrice, 2);
    }
}
