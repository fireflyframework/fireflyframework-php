<?php

declare(strict_types=1);

namespace App\Http;

use Firefly\Validation\Constraint\CountryCode;
use Firefly\Validation\Constraint\NotBlank;
use Firefly\Validation\Constraint\Pattern;
use Firefly\Validation\Constraint\Size;

/**
 * Where an order ships to.
 *
 * `country` is an ISO 3166-1 alpha-2 code (`GB`, `ES`, `NL`); `postcode` is validated loosely enough to
 * accept the common European and North American formats.
 */
final readonly class AddressPayload
{
    // NOTE — THE DOCBLOCK ABOVE IS PUBLISHED AS THE SCHEMA DESCRIPTION in /openapi.json; line comments
    // like this one are not.
    //
    // THE NESTED HALF OF OrderRequest, and the reason it is a class rather than four flattened `shipTo*`
    // fields. `#[Valid] AddressPayload $shipTo` on OrderRequest makes the ConstraintScanner cascade the
    // rules below into DOT KEYS — `shipTo.street`, `shipTo.postcode`, … — so a bad postcode comes back as a
    // 422 whose field error names the exact JSON path the client sent, and firefly/openapi emits this class
    // as its own reusable component that the order schema $refs.
    //
    // #[CountryCode] is one of the first-party constraint OBJECTS (alongside #[Iban], #[Bic], #[Luhn],
    // #[Isin], #[PostalCode] and friends): a real ISO 3166-1 alpha-2 membership check, not a regex that
    // merely looks like one.

    public function __construct(
        #[NotBlank]
        #[Size(max: 120)]
        public string $street,
        #[NotBlank]
        #[Size(max: 80)]
        public string $city,
        #[NotBlank]
        #[Pattern('/^[A-Z0-9][A-Z0-9 -]{2,9}$/D')]
        public string $postcode,
        #[NotBlank]
        #[CountryCode]
        public string $country,
    ) {}
}
