<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\Fixture;

use Firefly\Validation\Constraint\CountryCode;
use Firefly\Validation\Constraint\NotBlank;
use Firefly\Validation\Constraint\Size;

/** The nested #[Valid] target: must become its own component and be reached by $ref, never inlined. */
final class AddressPayload
{
    public function __construct(
        #[NotBlank] public readonly string $line1,
        #[NotBlank] #[Size(min: 2, max: 10)] public readonly string $postcode,
        #[CountryCode] public readonly ?string $country = null,
    ) {}
}
