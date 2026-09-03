<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\Fixture\Shipping;

use Firefly\Validation\Constraint\NotBlank;

/** The other half of the short-name collision — the SECOND claimant, which must fall back to a dotted FQN. */
final class Address
{
    public function __construct(#[NotBlank] public readonly string $shippingLine) {}
}
