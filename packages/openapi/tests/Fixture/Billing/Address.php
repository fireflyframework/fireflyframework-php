<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\Fixture\Billing;

use Firefly\Validation\Constraint\NotBlank;

/** Half of a deliberate short-name collision with Shipping\Address — see SchemaRegistry's naming rule. */
final class Address
{
    public function __construct(#[NotBlank] public readonly string $billingLine) {}
}
