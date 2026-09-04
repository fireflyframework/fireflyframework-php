<?php

declare(strict_types=1);

namespace App\Orders;

/**
 * One line of an order: what was bought, how many, and at what unit price.
 *
 * `subtotal()` is here rather than in the controller or the service because it is a fact about a line, not
 * about a request or a use case — the small habit that keeps a domain model from decaying into a bag of
 * public properties with all the behaviour somewhere else.
 */
final readonly class OrderLine
{
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
