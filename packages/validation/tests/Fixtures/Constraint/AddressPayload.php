<?php

declare(strict_types=1);

namespace Firefly\Validation\Tests\Fixtures\Constraint;

use Firefly\Validation\Constraint\NotBlank;
use Firefly\Validation\Constraint\PostalCode;

final class AddressPayload
{
    public function __construct(
        #[NotBlank]
        public readonly string $street,
        #[PostalCode]
        public readonly string $postcode,
    ) {}
}
