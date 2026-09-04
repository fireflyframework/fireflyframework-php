<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\Fixture;

/**
 * The four kinds of constructor default a real DTO carries, so the generator's rule for `default` can be
 * asserted on all of them at once: two that HAVE a JSON spelling, and two that do not.
 *
 * A default the document states is a promise a client can act on — omit the member and the server will apply
 * exactly this value. `Currency::EUR` and `new AddressPayload(...)` have no JSON literal a client could send
 * back, so an approximation of either ("EUR", `{}`) would be a `default` the server never actually applies.
 */
final readonly class DefaultsPayload
{
    public function __construct(
        public string $label = 'draft',
        public int $retries = 3,
        public Currency $currency = Currency::EUR,
        public ?AddressPayload $address = new AddressPayload('', ''),
    ) {}
}
