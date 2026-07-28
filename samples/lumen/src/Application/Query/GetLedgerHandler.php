<?php

declare(strict_types=1);

namespace Lumen\Application\Query;

use Firefly\Cqrs\Attributes\QueryHandler;
use Lumen\Domain\LedgerEntry;

/**
 * Handles GetLedger: returns the wallet's ledger rows in insertion order. S5's projector writes `ledger_entries`, so
 * this reads the projected rows directly (an empty list for a wallet with no entries yet). A pure read —
 * no #[Transactional]; the query type is inferred from the handle() parameter.
 */
#[QueryHandler]
final class GetLedgerHandler
{
    /** @return list<LedgerEntry> */
    public function handle(GetLedger $query): array
    {
        return array_values(
            LedgerEntry::query()
                ->where('wallet_id', $query->walletId)
                ->orderBy('id')
                ->get()
                ->all()
        );
    }
}
