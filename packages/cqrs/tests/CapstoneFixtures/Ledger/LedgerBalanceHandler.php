<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\CapstoneFixtures\Ledger;

use Firefly\Cqrs\Attributes\QueryHandler;

#[QueryHandler]
final class LedgerBalanceHandler
{
    public function handle(LedgerBalance $query): int
    {
        HandlerCalls::hit('balance');

        return (int) Entry::query()->where('account', $query->account)->sum('amount');
    }
}
