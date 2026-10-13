<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\CapstoneFixtures\Ledger;

use Firefly\Cqrs\Attributes\QueryHandler;

#[QueryHandler]
final class AccountStatementHandler
{
    /** @return list<int> */
    public function handle(AccountStatement $query): array
    {
        HandlerCalls::hit('statement');

        $amounts = [];
        foreach (Entry::query()->where('account', $query->account)->orderBy('id')->get() as $entry) {
            $amounts[] = $entry->amount;
        }

        return $amounts;
    }
}
