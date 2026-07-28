<?php

declare(strict_types=1);

namespace Lumen\Web\Dto;

use Firefly\Validation\Constraint\CurrencyCode;
use Firefly\Validation\Constraint\NotBlank;

/**
 * Validated request body for `POST /api/v1/wallets`: an owner id and an ISO-4217 currency code. `#[Valid]` on the
 * controller parameter runs BeanValidator against these constraints BEFORE the DTO is hydrated (ArgumentResolver),
 * so an invalid body never reaches WalletController::open() — it renders as a 422 RFC-7807 payload instead.
 */
final class OpenWalletRequest
{
    public function __construct(
        #[NotBlank]
        public readonly string $owner_id,
        #[NotBlank]
        #[CurrencyCode]
        public readonly string $currency,
    ) {}
}
