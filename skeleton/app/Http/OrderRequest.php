<?php

declare(strict_types=1);

namespace App\Http;

use Firefly\Validation\Constraint\Email;
use Firefly\Validation\Constraint\NotBlank;
use Firefly\Validation\Constraint\NotEmpty;
use Firefly\Validation\Constraint\NotNull;
use Firefly\Validation\Constraint\Size;
use Firefly\Validation\Valid;

/**
 * An order to be placed or replaced: who is buying, where it ships, and what is on it.
 *
 * `lines` must hold between 1 and 50 entries, each a complete line. `shipTo` is required in full — its own
 * fields are validated and reported under their dotted paths (`shipTo.postcode`), and so is every line
 * (`lines[1].sku`).
 */
final readonly class OrderRequest
{
    // NOTE — THE DOCBLOCK ABOVE IS PUBLISHED AS THE SCHEMA DESCRIPTION in /openapi.json; line comments
    // like this one are not. Describe the payload up there for the people who will send it; keep the
    // framework notes down here.
    //
    // THE THREE SHAPES ON PURPOSE — a scalar, a NESTED DTO and a LIST of DTOs — because those exercise every
    // part of the pipeline at once:
    //
    //  * HYDRATION. RouteScanner compiles a shape table for this class at cache time (the one sanctioned
    //    reflection site in firefly/web), so the per-request ArgumentResolver builds `shipTo` as an
    //    AddressPayload and every element of `lines` as an OrderLinePayload without reflecting at all. The
    //    list element type comes from the `@param list<OrderLinePayload>` tag below — or from
    //    `#[Valid(each: OrderLinePayload::class)]` on a class with no docblock — and NOWHERE else.
    //  * VALIDATION. `#[Valid]` on `$shipTo` makes the ConstraintScanner cascade AddressPayload's rules into
    //    dot keys, so a bad postcode is reported as `shipTo.postcode` — the path the client actually sent.
    //    `#[Valid]` on `$lines` cascades into every ELEMENT, reading the same `@param` tag hydration reads,
    //    so a bad SKU on the second line is reported as `lines[1].sku` with the Pattern constraint's own
    //    sentence — a 422 a client can act on, never a 400 from the element's constructor. A `#[Valid]`
    //    list whose element class the framework cannot see is refused by `firefly:cache`, not skipped.
    //  * DOCUMENTATION. firefly/openapi reads the same compiled rules and the same shape table, so
    //    `required`, `maxLength`, the `$ref` to AddressPayload and the `items: $ref` to OrderLinePayload all
    //    appear in /openapi.json without one annotation written for the document's benefit.
    //
    // WHAT A FIELD ERROR SAYS. Each entry of the 422's `errors` list carries the constraint's own sentence
    // (`must not be blank`, `size must be between 1 and 50`) and the constraint's name in `constraint`
    // (`NotBlank`, `Size`) — the shape of Spring's FieldError. Add `message:` to any constraint to say it in
    // your own words; set `firefly.validation.messages` to `laravel` to get Laravel's sentences back.
    //
    // WHY EVERY REQUIRED PROPERTY ALSO CARRIES #[NotNull] OR #[NotEmpty]. Rule OBJECTS (#[Size],
    // #[CountryCode]) are not "implicit" to Illuminate, so they are skipped entirely for a key that is
    // absent: a body with no `lines` at all would sail past a lone #[Size(min: 1)] and then fail in the
    // constructor as a 400. The two implicit constraints are what turn a missing required field back into
    // the 422 it should be — and OrderLinePayload follows the same rule for its own numbers.

    /**
     * @param  list<OrderLinePayload>  $lines
     */
    public function __construct(
        #[NotBlank]
        #[Size(max: 120)]
        public string $customer,
        #[NotBlank]
        #[Email]
        public string $email,
        #[NotNull]
        #[Valid]
        public AddressPayload $shipTo,
        #[NotEmpty]
        #[Size(min: 1, max: 50)]
        #[Valid]
        public array $lines,
    ) {}
}
