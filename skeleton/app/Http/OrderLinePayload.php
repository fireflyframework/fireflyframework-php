<?php

declare(strict_types=1);

namespace App\Http;

use Firefly\Validation\Constraint\Max;
use Firefly\Validation\Constraint\NotBlank;
use Firefly\Validation\Constraint\Pattern;
use Firefly\Validation\Constraint\Positive;
use Firefly\Validation\Constraint\PositiveOrZero;

/**
 * One line of an order: a product, how many of it, and the price per unit at the time of ordering.
 */
final readonly class OrderLinePayload
{
    // NOTE — THE DOCBLOCK ABOVE IS PUBLISHED AS THE SCHEMA DESCRIPTION in /openapi.json; line comments
    // like this one are not.
    //
    // THE ELEMENT TYPE OF OrderRequest's LIST, and the one shape PHP cannot describe on its own. An `array`
    // carries no element type, so the ONLY place the framework can learn that `$lines` holds these is the
    // `@param list<OrderLinePayload> $lines` tag on OrderRequest's constructor. RouteScanner reads that tag
    // at cache time and records it in the route's binding plan, which is what lets the ArgumentResolver
    // build each element as an OrderLinePayload instead of handing the controller a bag of raw arrays.
    // Delete the tag and the framework has nothing to go on: the sub-arrays reach this constructor, which
    // refuses them, and the request is answered with a 400 naming `lines[0]`.

    public function __construct(
        #[NotBlank]
        #[Pattern('/^[A-Z0-9][A-Z0-9-]{2,31}$/D')]
        public string $sku,
        #[Positive]
        #[Max(999)]
        public int $quantity,
        #[PositiveOrZero]
        public float $unitPrice,
    ) {}
}
