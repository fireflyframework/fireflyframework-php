<?php

declare(strict_types=1);

namespace Lumen\Web\Dto;

use Firefly\Validation\Constraint\Positive;

/**
 * Validated request body shared by `POST /api/v1/wallets/{id}/deposit` and `.../withdraw`: a strictly positive
 * amount in minor units. Both Deposit and Withdraw take an existing wallet id from the path and only the amount
 * from the body, so one DTO covers both endpoints.
 */
final class AmountRequest
{
    public function __construct(
        #[Positive]
        public readonly int $amount_minor,
    ) {}
}
