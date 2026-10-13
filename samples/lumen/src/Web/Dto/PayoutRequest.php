<?php

declare(strict_types=1);

namespace Lumen\Web\Dto;

use Firefly\Validation\Constraint\NotBlank;
use Firefly\Validation\Constraint\Positive;

/** Validated body of `POST /api/v1/wallets/{id}/payouts`: the destination account and a positive amount in minor units. */
final class PayoutRequest
{
    public function __construct(
        #[NotBlank]
        public readonly string $iban,
        #[Positive]
        public readonly int $amount_minor,
    ) {}
}
