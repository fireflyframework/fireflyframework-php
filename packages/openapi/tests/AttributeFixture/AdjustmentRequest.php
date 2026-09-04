<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\AttributeFixture;

use Firefly\OpenApi\Attributes\ApiProperty;
use Firefly\Validation\Constraint\Email;
use Firefly\Validation\Constraint\NotBlank;

/**
 * A manual correction to a stock level, for when a count disagrees with the ledger.
 */
final class AdjustmentRequest
{
    public function __construct(
        /** A property docblock the attribute beside it must beat. */
        #[ApiProperty(description: 'The stock-keeping unit being corrected.', example: 'ACME-001')]
        #[NotBlank]
        public readonly string $sku,
        #[ApiProperty(description: 'Signed change to apply. Negative writes stock off.', example: -3)]
        public readonly int $delta,
        // #[Email] already produces `format: email`; the attribute states the more precise one, and the
        // override is the point of the assertion that reads it back.
        #[ApiProperty(format: 'idn-email', deprecated: true)]
        #[Email]
        public readonly ?string $countedBy = null,
    ) {}
}
