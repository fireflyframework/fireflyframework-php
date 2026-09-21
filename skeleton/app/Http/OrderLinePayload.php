<?php

declare(strict_types=1);

namespace App\Http;

use Firefly\Validation\Constraint\Max;
use Firefly\Validation\Constraint\NotBlank;
use Firefly\Validation\Constraint\NotNull;
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
    // `@param list<OrderLinePayload> $lines` tag on OrderRequest's constructor (or an explicit
    // `#[Valid(each: OrderLinePayload::class)]`). One resolver reads it for everyone: RouteScanner records it
    // in the route's binding plan so the ArgumentResolver builds each element as an OrderLinePayload, and
    // ConstraintScanner cascades OrderRequest's `#[Valid]` into each element so the constraints below run
    // per line and a failure is reported as `lines[1].sku` — with the Pattern constraint's sentence, not
    // the constructor's refusal. Delete the tag and `firefly:cache` refuses the class rather than guessing.
    //
    // #[NotNull] ON THE NUMBERS for the reason OrderRequest gives for its own required fields: `numeric` and
    // the bound rules are skipped for an ABSENT key, so a line sent without a quantity would otherwise pass
    // validation and die in this constructor as a 400. With it, the answer is `lines[0].quantity — must not
    // be null`.

    public function __construct(
        #[NotBlank]
        #[Pattern('/^[A-Z0-9][A-Z0-9-]{2,31}$/D')]
        public string $sku,
        #[NotNull]
        #[Positive]
        #[Max(999)]
        public int $quantity,
        #[NotNull]
        #[PositiveOrZero]
        public float $unitPrice,
    ) {}
}
