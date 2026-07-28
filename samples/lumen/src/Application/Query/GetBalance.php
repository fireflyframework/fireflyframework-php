<?php

declare(strict_types=1);

namespace Lumen\Application\Query;

/**
 * Query: read a wallet's current balance in minor units. GetBalanceHandler returns null for an unknown wallet.
 */
final readonly class GetBalance
{
    public function __construct(public string $walletId) {}
}
