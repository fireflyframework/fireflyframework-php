<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\Fixture;

use Firefly\Validation\Constraint\DecimalScale;
use Firefly\Validation\Constraint\Email;
use Firefly\Validation\Constraint\Max;
use Firefly\Validation\Constraint\Min;
use Firefly\Validation\Constraint\NotBlank;
use Firefly\Validation\Constraint\NotNull;
use Firefly\Validation\Constraint\Pattern;
use Firefly\Validation\Constraint\Positive;
use Firefly\Validation\Constraint\Size;
use Firefly\Validation\Constraint\UuidValue;
use Firefly\Validation\Valid;

/**
 * The request body under test. Deliberately spans every mapping route the generator has: a string with a
 * length bound, a Jakarta null-contract nullable, an int with numeric bounds, a scaled decimal, a backed
 * enum, a nested #[Valid] DTO, a PCRE pattern, and a rule OBJECT (UuidValue -> Rule\Uuid).
 */
final class CreateOrderRequest
{
    public function __construct(
        #[NotBlank] #[Size(max: 64)] public readonly string $reference,
        #[NotNull] #[Email] public readonly string $email,
        #[Min(1)] #[Max(999)] public readonly int $quantity,
        #[Positive] #[DecimalScale(2)] public readonly float $amount,
        public readonly Currency $currency,
        #[Valid] public readonly AddressPayload $shipTo,
        #[Pattern('/^[A-Z]{3}-\d{4}$/D')] public readonly ?string $coupon = null,
        #[UuidValue] public readonly ?string $idempotencyKey = null,
    ) {}
}
