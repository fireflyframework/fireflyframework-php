<?php

declare(strict_types=1);

namespace Lumen\Application\Query;

/**
 * Query: read the ledger rows for a wallet. S5's projector populates `ledger_entries`, so this returns the wallet's
 * projected entries in insertion order (an empty list only for a wallet with none yet).
 */
final readonly class GetLedger
{
    public function __construct(public string $walletId) {}
}
