<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\CapstoneFixtures\Ledger;

use Firefly\Cqrs\Attributes\QueryHandler;

#[QueryHandler]
final class LedgerTotalsHandler
{
    public function handle(LedgerTotals $query): int
    {
        HandlerCalls::hit('totals');

        return (int) Entry::query()->sum('amount');
    }
}
