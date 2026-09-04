<?php

declare(strict_types=1);

namespace Firefly\Web\Tests\Fixtures;

use Firefly\Validation\Constraint\NotBlank;
use Firefly\Validation\Valid;

/**
 * The nested DTO the #[Valid] cascade descends into: ConstraintScanner compiles its rules under the
 * dot-prefixed keys `beneficiary.street` / `beneficiary.postcode`, which is precisely why the hydration
 * defect was invisible for so long — validation passed, and only `new MoneyTransferRequest(...)` blew up.
 */
final class AddressPayload
{
    public function __construct(
        #[NotBlank]
        public readonly string $street,
        #[NotBlank]
        public readonly string $postcode,
        #[Valid]
        public readonly ?GeoPoint $geo = null,
    ) {}
}
