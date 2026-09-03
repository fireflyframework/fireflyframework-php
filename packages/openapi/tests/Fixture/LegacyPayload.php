<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\Fixture;

use Firefly\Validation\Constraint\Email;
use Firefly\Validation\Constraint\NotBlank;

/**
 * A DTO whose contract is wider than its constructor. ConstraintScanner reads plain typed properties as well
 * as promoted ones, and BeanValidator validates the RAW decoded body — so `legacyContact` is enforced on
 * input even though ArgumentResolver never assigns it. A generator that documented only constructor
 * parameters would publish a schema the server rejects payloads against.
 */
final class LegacyPayload
{
    #[NotBlank]
    #[Email]
    public string $legacyContact = '';

    public function __construct(#[NotBlank] public readonly string $name) {}
}
