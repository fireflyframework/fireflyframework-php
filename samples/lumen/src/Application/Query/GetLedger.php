<?php

declare(strict_types=1);

namespace Lumen\Application\Query;

/**
 * Query: read the ledger rows for a wallet. Until S5's projector populates `ledger_entries`, this returns whatever is
 * persisted there (an empty list for a wallet with no projected entries yet).
 */
final readonly class GetLedger
{
    public function __construct(public string $walletId) {}
}
