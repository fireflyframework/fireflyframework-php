<?php

declare(strict_types=1);

namespace Lumen\Web\Dto;

use Firefly\Validation\Constraint\NotBlank;
use Firefly\Validation\Constraint\Positive;

/**
 * Validated request body for `POST /api/v1/wallets/transfers`: both wallet ids travel in the body (a transfer is
 * not scoped under a single wallet's path) plus a strictly positive amount in minor units.
 */
final class TransferRequest
{
    public function __construct(
        #[NotBlank]
        public readonly string $source_wallet_id,
        #[NotBlank]
        public readonly string $destination_wallet_id,
        #[Positive]
        public readonly int $amount_minor,
    ) {}
}
