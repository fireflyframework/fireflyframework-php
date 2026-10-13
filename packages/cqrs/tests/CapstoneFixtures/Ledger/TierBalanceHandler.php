<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\CapstoneFixtures\Ledger;

use Firefly\Cqrs\Attributes\QueryHandler;

#[QueryHandler]
final class TierBalanceHandler
{
    public function handle(TierBalance $query): int
    {
        HandlerCalls::hit('tier');

        return (int) Entry::query()->where('account', $query->account)->sum('amount');
    }
}
