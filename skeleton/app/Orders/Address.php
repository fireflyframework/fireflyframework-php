<?php

declare(strict_types=1);

namespace App\Orders;

/**
 * A postal address: where an order ships.
 */
final readonly class Address
{
    // NOTE — THE DOCBLOCK ABOVE IS PUBLISHED as this component's `description` in /openapi.json; this comment
    // is not. See App\Orders\Order for the rule.
    //
    // It is deliberately a DIFFERENT class from App\Http\AddressPayload, which is the same four fields as
    // they arrive over HTTP. The duplication is the point: the payload carries validation attributes and is
    // shaped by the wire format, this one is shaped by the domain and is free to change without breaking a
    // published API. App\Http\OrderController owns the translation between them, which is why nothing under
    // App\Orders imports anything from App\Http — a dependency direction worth keeping as the application
    // grows.

    public function __construct(
        public string $street,
        public string $city,
        public string $postcode,
        public string $country,
    ) {}
}
