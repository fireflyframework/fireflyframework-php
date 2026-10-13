<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\CapstoneFixtures\Ledger;

use Firefly\Cqrs\Attributes\InvalidatesQuery;

/** Evicts one TierBalance in process; from another process its JSON payload can only evict the group. */
#[InvalidatesQuery(TierBalance::class, with: ['account' => 'account', 'tier' => 'tier'])]
final readonly class TierChanged
{
    public function __construct(public string $account, public LedgerTier $tier) {}
}
